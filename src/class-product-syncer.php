<?php

class WP_Product_Syncer extends Wp_Post_Syncer{

	/**
	 */
	function __construct(){

		$this->post_type = 'product';

	}

}
