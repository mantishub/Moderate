<?php
/**
 * MantisBT - A PHP based bugtracking system
 *
 * MantisBT is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * MantisBT is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 */

require_api( 'access_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'print_api.php' );

# Check access
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

# Handle form submission
$f_save = gpc_get_bool( 'save', false );

if( $f_save ) {
	form_security_validate( 'plugin_Moderate_config' );

	$f_moderate_threshold = gpc_get_int( 'moderate_threshold' );
	$f_moderate_bypass_threshold = gpc_get_int( 'moderate_bypass_threshold' );

	plugin_config_set( 'moderate_threshold', $f_moderate_threshold );
	plugin_config_set( 'moderate_bypass_threshold', $f_moderate_bypass_threshold );

	form_security_purge( 'plugin_Moderate_config' );

	print_header_redirect( plugin_page( 'config', /* redirect */ true ) );
}

layout_page_header( plugin_lang_get( 'config_title' ) );
layout_page_begin();
?>

<div class="col-md-12 col-xs-12">
<div class="space-10"></div>
<div class="widget-box widget-color-blue2">
<div class="widget-header widget-header-small">
	<h4 class="widget-title lighter">
		<?php print_icon( 'fa-sliders', 'ace-icon' ); ?>
		<?php echo plugin_lang_get( 'config_title' ) ?>
	</h4>
</div>
<div class="widget-body">
<div class="widget-main no-padding">
<form action="<?php echo plugin_page( 'config' ) ?>" method="post">
<?php echo form_security_field( 'plugin_Moderate_config' ) ?>
<div class="table-responsive">
<table class="table table-bordered table-condensed">
	<tr>
		<th class="category width-30">
			<label for="moderate_threshold"><?php echo plugin_lang_get( 'config_moderate_threshold' ) ?></label>
		</th>
		<td>
			<select name="moderate_threshold" id="moderate_threshold" class="input-sm">
				<?php print_enum_string_option_list( 'access_levels', plugin_config_get( 'moderate_threshold' ) ) ?>
			</select>
		</td>
	</tr>
	<tr>
		<th class="category">
			<label for="moderate_bypass_threshold"><?php echo plugin_lang_get( 'config_bypass_threshold' ) ?></label>
		</th>
		<td>
			<select name="moderate_bypass_threshold" id="moderate_bypass_threshold" class="input-sm">
				<?php print_enum_string_option_list( 'access_levels', plugin_config_get( 'moderate_bypass_threshold' ) ) ?>
			</select>
		</td>
	</tr>
</table>
</div>
<div class="widget-toolbox padding-8 clearfix">
	<input type="submit" name="save" class="btn btn-primary btn-white btn-round" value="<?php echo lang_get( 'change_configuration' ) ?>" />
</div>
</form>
</div>
</div>
</div>
</div>

<?php
layout_page_end();
