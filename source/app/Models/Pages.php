<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pages extends Model
{
    protected $table = 'pages';

    protected $fillable = ['page_title', 'page_slug', 'page_content', 'page_order', 'status'];

 
	
	 public $timestamps = false;


	public static function getPageInfo($id,$field_name) 
    { 
		$page_info = Pages::where('status','1')->where('id',$id)->first();
		
		if($page_info)
		{
			return  $page_info->$field_name;
		}
		else
		{
			return  '';
		}
	}
    
}
