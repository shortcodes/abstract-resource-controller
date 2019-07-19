<?php

namespace Shortcodes\AbstractResourceController\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Shortcodes\AbstractResourceController\Resources\DefaultResource;
use Illuminate\Support\Str;


abstract class AbstractResourceController extends Controller
{
    protected $modelClassName;
    protected $modelClass;
    protected $resourceClass;
    protected $modelObject;

    public function __construct(Request $request)
    {
        if (!Route::getCurrentRoute()) {
            return;
        }

        $this->modelClass = (new \ReflectionClass($this->model))->getShortName();
        $this->modelClassNameSnake = Str::snake((new \ReflectionClass($this->model))->getShortName());
        $this->modelObject = new $this->model();

        $resourceClass = $this->getResourceClass($this->modelClass, true);

        $this->resourceClass = $resourceClass;

        $requestClass = $this->getRequestClass($this->modelClass);

        if (class_exists($requestClass)) {
            app($requestClass);
        }

        $routeParameters = $request->route()->parameters();

        foreach ($routeParameters as $parameter => $routeParameter) {
            $request->route()->setParameter($parameter, $this->model::findOrFail($routeParameter));
        }
    }

    public function index()
    {
        $searchResult = null;

        if (in_array(\ScoutElastic\Searchable::class, class_uses($this->model)) && method_exists($this->model, "scout")) {

            $scout = $this->model::scout(request());
            $collection = $this->resourceClass::collection($scout->paginate(request()->get('length', 10), 'page', request()->get('page', 0)));

            if (!empty($this->modelObject->aggregateRules)) {
                $aggregations = $scout->aggregate();
                $collection->additional(['meta' => [
                    'aggregations' => $this->getAggregates($aggregations),
                ]]);
            }


            return $collection;
        }

        if (method_exists($this->model, "searchQuery")) {
            $searchResult = $this->model::searchQuery(request());
        } elseif (method_exists($this->model, "search")) {
            $searchResult = $this->model::search(request());
        }


        if ($searchResult !== null && !isset($searchResult['data'])) {
            return $this->resourceClass::collection($searchResult === null ? $this->model::all() : $searchResult);
        }

        if ($searchResult !== null) {
            $searchResult['data'] = $this->resourceClass::collection($searchResult === null ? $this->model::all() : $searchResult['data']);
        }

        if ($searchResult === null) {
            $searchResult = $this->resourceClass::collection($this->model::paginate(request()->get('length', 10), 'page', request()->get('page', 0)));
        }

        return $searchResult;

    }


    public function store()
    {
        try {

            DB::beginTransaction();

            $model = $this->model::create(request()->all());

            DB::commit();

            return new $this->resourceClass($model);

        } catch (\Exception $e) {
            return $this->responseWithError($e);
        }

    }

    public function show()
    {
        $model = request()->route($this->modelClassNameSnake);

        return new $this->resourceClass($model);
    }

    public function update()
    {
        try {


            $model = request()->route($this->modelClassNameSnake);

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

            $model = request()->route($this->modelClassNameSnake);

            DB::beginTransaction();

            $model->delete();

            DB::commit();

            return response()->json([], 204);

        } catch (\Exception $e) {
            return $this->responseWithError($e);
        }
    }

    private function getResourceClass($modelClassName, $forList = false)
    {
        $resourceClass = 'App\Http\Resources' . (request()->header('X-Web-Call') ? 'ForWeb' : '') . '\\' . ucfirst($modelClassName) . ($forList ? 'List' : '') . 'Resource';

        if (!class_exists($resourceClass) && $forList) {
            $resourceClass = 'App\Http\Resources' . (request()->header('X-Web-Call') ? 'ForWeb' : '') . '\\' . ucfirst($modelClassName) . 'Resource';
        }

        if (class_exists($resourceClass)) {
            return $resourceClass;
        }

        return DefaultResource::class;

    }

    private function getRequestClass($modelClassName)
    {
        return 'App\Http\Requests' . '\\' . ucfirst(str_plural($modelClassName)) . '\\' . ucfirst(Route::getCurrentRoute()->getActionMethod()) . ucfirst($modelClassName) . 'Request';

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

    public function getAggregates($aggregations)
    {
        $result = [];

        foreach ($aggregations['aggregations'] as $k => $aggregation) {
            $collection = collect($aggregations['aggregations'][$k]['buckets'])->pluck('doc_count', 'key')->toArray();
            $result[$k] = $collection;
        }

        return $result;
    }
}
