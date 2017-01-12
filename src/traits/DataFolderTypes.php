<?php

/**
 * list default DataFolder names and their types
 * User: kene
 * Date: 2016-11-14
 * Time: 4:28 PM
 */

namespace ariad\exacttargetLaravel;

trait DataFolderTypes {

	private $types = [
		 'filterdefinition' => 'Data Filters',
		 'email' => 'my emails',
		 'automated_email' => 'simple automated emails',
		 'template' => 'my templates',
		 'list' => 'my lists',
		 'job' => 'my tracking',
		 'content' => 'my contents',
		 'document' => 'my documents',
		 'survey' => 'my surveys',
		 'image' => 'my images',
		 'media' => 'Portfolio',
		 'group' => 'my groups',
		 'mysubs' => 'my subscribers',
		 'queryactivity' => 'Query Activity',
		 'suppression_list' => 'Suppression Lists',
		 'campaign' => 'Campaign',
		 'condensedlpview' => 'Condensed preview',
		 'dataextension' => 'Data extensions',
		 'filteractivity' => 'Filter activities',
		 'global_email' => 'Global email',
		 'global_email_sub' => 'Global email subscribers',
		 'livecontent' => 'Live content',
		 'measure' => 'Measures',
		 'microsite' => 'Microsites',
		 'micrositelayout' => 'Microsite layouts',
		 'organization' => 'Organizations',
		 'programs2' => 'Programs',
		 'publication' => 'Publication lists',
		 'shared_content' => 'Shared content',
		 'shared_data' => 'Shared data',
		 'shared_dataextension' => 'Shared data extensions',
		 'shared_email' => 'Shared email messages',
		 'shared_item' => 'Shared items',
		 'shared_portfolio' => 'Shared portfolios',
		 'shared_publication' => 'Shared publication lists',
		 'shared_suppression_list' => 'Shared suppression lists',
		 'shared_survey' => 'Shared surveys',
		 'shared_template' => 'Shared templates',
		 'triggered_send' => 'Triggered sends',
		 'userinitiatedsends' => 'User-initiated sends',
	];

	public function check_type($type) {
		$types = array_keys($this->types);
		return in_array($type, $types);
	}

	public function get_type($folder) {
		return array_search($folder, $this->types);
	}


}