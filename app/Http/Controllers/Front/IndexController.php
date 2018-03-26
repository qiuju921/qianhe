<?php
namespace App\Http\Controllers\Front;

use Input, Log;
use App\Http\Controllers\BaseController;

class IndexController extends BaseController {
    function __construct() {
    }
    
    /**
     * @name 首页视图
     */
    public function indexView() {
        $data = [];
        return view('front/index');
    }
    /**
     * @name 接口
     */
    public function index() {
        $rules = [
        //             'mall_id' => 'required',
        ];
        $params = Input::all();
        if ($this->_checkParams($params, $rules) !== true) {
            return $this->_jsonOutput();
        }
    }
}
