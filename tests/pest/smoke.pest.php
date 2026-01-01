<?php

it( 'loads the test bootstrap', function () {
	expect( function_exists( 'get_option' ) )->toBeTrue();
} );
