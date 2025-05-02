<?php

namespace App\Helpers;

class CommonHelper
{
    /*
     Function name : getPagination
     Description : This function used for set the pagination
    */
    public function getPagination($query, $page, $limit)
    {
        $perPage = $limit ? $limit : 10;
        $totalCount = $query->count();
        $getAllData = $query->limit($perPage)->offset(($page - 1) * $perPage)->get()->toArray();

        return [
            'data' => $getAllData,
            'total_counts' => $totalCount,
            'total_pages' => $totalCount ? ceil($totalCount / $perPage) : 0,
        ];
    }



    public function success($statusCode = 200, $result, $message, $paginationData = null, $replaceNullWithEmpty = true)
    {
        $result = json_decode(json_encode($result), true);

        if ($result === null) {
            $result = [];
        }

        if ($replaceNullWithEmpty && is_array($result)) {
            array_walk_recursive($result, function (&$item) {
                $item = ($item === null) ? "" : $item;
            });
        }

        $response = [
            'status' => $statusCode,
            'success' => true,
            'message' => $message,
            'data' => $result,
        ];

        if (!is_null($paginationData) && is_array($paginationData)) {
            $response = array_merge($response, $paginationData);
        }

        return response()->json($response, $statusCode);
    }



    /*
    Function name : error
    Description : Common function to return a error response.
*/
    public function error($error, $errorMessages = [], $code = 404)
    {
        $response = [
            'status' => $code,
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['data'] = [$errorMessages];
        }

        return response()->json($response, $code);
    }
}
