<?php

class WP_Product_Syncer extends WP_Post_Syncer{

	/**
	 */
	function __construct(){

		$this->post_type = 'product';

	}

}
