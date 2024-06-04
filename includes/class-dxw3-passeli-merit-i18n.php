<?php

class Dxw3_Passeli_Merit_i18n {

	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'dxw3-passeli-merit',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}
}
