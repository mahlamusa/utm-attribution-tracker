<?php
/**
 * Ninja Forms merge-tag provider for Utmatra.
 *
 * Required lazily by NinjaForms::register_merge_tags() — never by Bootstrap
 * — because its parent class, `NF_Abstracts_MergeTags`, only exists once
 * Ninja Forms core has loaded.
 *
 * @package Utmatra\Integrations\Forms
 */

namespace Utmatra\Integrations\Forms;

use NF_Abstracts_MergeTags;

defined( 'ABSPATH' ) || exit;

/**
 * NinjaFormsMergeTags
 *
 * @since 1.0.0
 */
class NinjaFormsMergeTags extends NF_Abstracts_MergeTags {

	/**
	 * Provider ID — must match the array key it is registered under
	 * (`Ninja_Forms()->merge_tags['utmatra_merge_tags']`).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $id = 'utmatra_merge_tags';

	/**
	 * Build the merge-tag list, one entry per recognised attribution field.
	 *
	 * Each tag's callback resolves lazily through NinjaForms::resolve() at
	 * render time, so it always reflects the current request's attribution
	 * cookie rather than a value captured at construction.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		$this->title = 'Utmatra';

		$tags = array();

		foreach ( NinjaForms::merge_tag_params() as $param => $field_name ) {
			$tags[ $param ] = array(
				'id'       => $param,
				'tag'      => '{utmatra:' . $param . '}',
				'label'    => $field_name,
				'callback' => static function () use ( $param ) {
					return NinjaForms::resolve( $param );
				},
			);
		}

		$this->merge_tags = $tags;
	}
}
