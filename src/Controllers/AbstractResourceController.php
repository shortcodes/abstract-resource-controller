<?php

namespace Shortcodes\AbstractResourceController\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

abstract class AbstractResourceController extends Controller
{

    private $modelClassName;

    public function __construct(Request $request)
    {
        $this->modelClassName = $this->getModelClassName($this->model);

        if (!Route::getCurrentRoute()) {
            return;
        }

        $requestClass = $this->getRequestClass($this->modelClassName);

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
        $resourceClass = $this->getResourceClass($this->modelClassName);

        if (method_exists($this->model, "search")) {
            $searchResult = $this->model::search(request());
        }

        if (isset($searchResult['data']) && isset($searchResult['meta'])) {
            return $resourceClass::collection($searchResult['data'])->additional(['meta' => $searchResult['meta']]);
        }

        return $resourceClass::collection($searchResult === null ? $this->model::all() : $searchResult);
    }


    public function store()
    {
        try {

            DB::beginTransaction();

            $model = $this->model::create(request()->all());

            DB::commit();

            $resourceClass = $this->getResourceClass($this->modelClassName);

            if (class_exists($resourceClass)) {
                return new $resourceClass($model);
            }

            return $model;
        } catch (\Exception $e) {
            return $this->responseWithError($e);
        }

    }

    public function show()
    {
        $model = request()->route($this->modelClassName);
        $resourceClass = $this->getResourceClass($this->modelClassName);

        if (class_exists($resourceClass)) {
            return new $resourceClass($model);
        }

        return $model;
    }

    public function update()
    {
        try {

            $model = request()->route($this->modelClassName);

            DB::beginTransaction();

            $updatedModel = tap($model)->update(request()->all());

            DB::commit();

            $resourceClass = $this->getResourceClass($this->modelClassName);

            if (class_exists($resourceClass)) {
                return new $resourceClass($updatedModel);
            }

            return $updatedModel;

        } catch (\Exception $e) {
            return $this->responseWithError($e);
        }
    }

    public function destroy()
    {
        try {

            $model = request()->route($this->modelClassName);

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
        return 'App\Http\Resources' . '\\' . $modelClassName . 'Resource';
    }

    private function getRequestClass($modelClassName)
    {
        return 'App\Http\Requests' . '\\' . ucfirst(str_plural($modelClassName)) . '\\' . ucfirst(Route::getCurrentRoute()->getActionMethod()) . ucfirst($modelClassName) . 'Request';
    }

    private function getModelClassName($model)
    {
        return strtolower(join('', array_slice(explode('\\', $model), -1)));
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
