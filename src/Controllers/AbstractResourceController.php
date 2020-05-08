<?php

namespace Shortcodes\AbstractResourceController\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Shortcodes\AbstractResourceController\Resources\DefaultResource;


abstract class AbstractResourceController extends Controller
{
    protected $modelName;
    protected $resourceClass;
    protected $object;
    protected $model;
    protected $disableScout = false;
    protected $cacheable = false;
    protected $pagination = true;

    public function __construct(Request $request)
    {
        if (!Route::getCurrentRoute()) {
            return;
        }

        if (method_exists($this, "access")) {
            $this->access();
        }

        $this->object = new $this->model();
        $this->modelName = class_basename($this->object);

        if (!$this->resourceClass) {
            $this->resourceClass = $this->getResourceClass($this->modelName, !request()->get('full') && strpos(Route::currentRouteAction(), 'index') !== false);
        }

        $requestClass = $this->getRequestClass($this->modelName);

        if (class_exists($requestClass)) {
            app($requestClass);
        }

        $routeParameters = $request->route()->parameters();

        foreach ($routeParameters as $parameter => $routeParameter) {

            $model = $this->model::query();

            if (isset($this->object->allowTrashedTo) && is_array($this->object->allowTrashedTo) && method_exists($this->object, 'usesSoftDelete') && $this->object->usesSoftDelete()) {
                foreach ($this->object->allowTrashedTo as $method) {
                    if ((strpos(Route::currentRouteAction(), $method)) !== false) {
                        $model = $this->model::withTrashed();
                    }
                }
            }
            $request->route()->setParameter($parameter, $model->findOrFail($routeParameter));
        }
    }

    public function index()
    {
        if (!$this->disableScout && in_array(\ScoutElastic\Searchable::class, class_uses($this->model)) && method_exists($this->model, "scout")) {
            $page = request()->get('page', 0);
            $length = request()->get('length', 10);
            $scout = $this->model::scout(request());

            if (!$this->cacheable) {
                return $this->getCollection($scout, $page, $length);
            }

            return cache()->remember('scout_query:' . md5($scout->buildPayload()->toJson() . '-' . $page . '-' . $length . '-' . (auth()->check() ? auth()->id() : 'none')), $this->cacheable, function () use ($scout, $length, $page) {
                return $this->getCollection($scout, $page, $length)->response(request())->getData(true);
            });
        }

        $searchQuery = $this->model::query();

        if (method_exists($this->model, "search") && !in_array(\ScoutElastic\Searchable::class, class_uses($this->model))) {
            $searchQuery = $this->model::search(request());
        }

        if (request()->get('sort_by') && request()->get('sort_direction')) {
            $searchQuery->orderBy(request()->get('sort_by', 'id'), request()->get('sort_direction', 'desc'));
        }

        $result = null;

        if ($this->pagination) {
            $result = $searchQuery->paginate(request()->get('length', 10), ['*'], 'page', request()->get('page', 0));
        }

        if (!$this->pagination) {
            $result = $searchQuery->get();
        }

        $collection = $this->resourceClass::collection($result);

        if (method_exists($this->model, "addMeta")) {
            $collection->additional(['meta' => $this->object->addMeta(request())]);
        }

        return $collection;
    }

    public function store()
    {
        try {

            DB::beginTransaction();

            $model = $this->model::create(request()->all());

            DB::commit();

            return new $this->resourceClass($model);

        } catch (\Exception $e) {
            logger($e);
            return $this->responseWithError($e);
        }

    }

    public function show()
    {
        $model = request()->route(Str::snake($this->modelName));
        return new $this->resourceClass($model);
    }

    public function update()
    {
        try {

            $model = request()->route(Str::snake($this->modelName));

            DB::beginTransaction();

            $updatedModel = tap($model)->update(request()->all());

            DB::commit();

            return new $this->resourceClass($updatedModel);


        } catch (\Exception $e) {
            return $this->responseWithError($e);
        }
    }

    public function destroy()
    {
        try {

            $model = request()->route(Str::snake($this->modelName));

            DB::beginTransaction();

            $model->delete();

            DB::commit();

            return response()->json([], 204);

        } catch (\Exception $e) {
            return $this->responseWithError($e);
        }
    }

    private function getResourceClass($modelClassName, $forList = false, $force = false)
    {
        $resourceClass = 'App\Http\Resources' . (request()->header('X-Web-Call') ? 'ForWeb' : '') . '\\' . ucfirst($modelClassName) . ($forList ? 'List' : '') . 'Resource';

        if ($force && class_exists($resourceClass)) {
            return $resourceClass;
        } elseif ($force) {
            return DefaultResource::class;
        }

        $resourceClass = 'App\Http\Resources' . (request()->header('X-Web-Call') ? 'ForWeb' : '') . '\\' . ucfirst($modelClassName) . ($forList ? 'List' : '') . 'Resource';

        if (!class_exists($resourceClass)) {
            $resourceClass = 'App\Http\Resources' . (request()->header('X-Web-Call') ? 'ForWeb' : '') . '\\' . ucfirst($modelClassName) . 'Resource';
        } elseif (!class_exists($resourceClass)) {
            $resourceClass = 'App\Http\Resources' . (request()->header('X-Web-Call') ? 'ForWeb' : '') . '\\' . ucfirst($modelClassName) . ($forList ? 'List' : '') . 'Resource';
        } elseif (!class_exists($resourceClass)) {
            $resourceClass = 'App\Http\Resources' . '\\' . ucfirst($modelClassName) . ($forList ? 'List' : '') . 'Resource';
        } elseif (!class_exists($resourceClass)) {
            $resourceClass = 'App\Http\Resources' . '\\' . ucfirst($modelClassName) . 'Resource';
        }

        if (class_exists($resourceClass)) {
            return $resourceClass;
        }

        return DefaultResource::class;
    }

    private function getRequestClass($modelClassName)
    {
        return 'App\Http\Requests' . '\\' . ucfirst(Str::plural($modelClassName)) . '\\' . ucfirst(Route::getCurrentRoute()->getActionMethod()) . ucfirst($modelClassName) . 'Request';
    }

    public function responseWithError(\Exception $e)
    {
        DB::rollBack();

        logger($e);
        return response()->json([
            'message' => App::environment(['testing', 'production']) ?
                'An error ocurred. Please contact administrator.' :
                $e->getMessage()
        ], 500);
    }

    private function getCollection($scout, $page, $length)
    {
        $collection = $this->resourceClass::collection(
            $scout->paginate($length, 'page', $page)
        );

        if (method_exists($this->model, "addMeta")) {
            $collection->additional(['meta' => $this->object->addMeta(request(), ['scout' => $scout])]);
        }

        return $collection;
    }
}
