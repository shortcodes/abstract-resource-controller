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

    public function __construct(Request $request)
    {
        if (!Route::getCurrentRoute()) {
            return;
        }

        $this->modelClass = (new \ReflectionClass($this->model))->getShortName();
        $this->modelClassNameSnake = Str::snake((new \ReflectionClass($this->model))->getShortName());

        $resourceClass = $this->getResourceClass($this->modelClass);

        if (!class_exists($resourceClass)) {
            $resourceClass = DefaultResource::class;
        }

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

        if (method_exists($this->model, "searchQuery")) {
            $searchResult = $this->model::searchQuery(request());
        } elseif (method_exists($this->model, "search")) {
            $searchResult = $this->model::search(request());
        }

        if ($searchResult !== null) {
            $searchResult['data'] = $this->resourceClass::collection($searchResult === null ? $this->model::all() : $searchResult['data']);
        }

        if ($searchResult === null) {
            $searchResult = $this->resourceClass::collection($this->model::paginate());
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

    private function getResourceClass($modelClassName)
    {
        return 'App\Http\Resources' . '\\' . ucfirst($modelClassName) . 'Resource';
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
}
