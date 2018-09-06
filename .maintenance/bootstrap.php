<?php

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command( 'maintenance', 'WP_CLI\Maintenance\Maintenance_Namespace' );

WP_CLI::add_command( 'maintenance contrib-list', 'WP_CLI\Maintenance\Contrib_list_Command' );
WP_CLI::add_command( 'maintenance milestones-after', 'WP_CLI\Maintenance\Milestones_After_Command' );
WP_CLI::add_command( 'maintenance milestones-since', 'WP_CLI\Maintenance\Milestones_Since_Command' );
WP_CLI::add_command( 'maintenance release-date', 'WP_CLI\Maintenance\Release_Date_Command' );
WP_CLI::add_command( 'maintenance release-notes', 'WP_CLI\Maintenance\Release_Notes_Command' );
WP_CLI::add_command( 'maintenance replace-label', 'WP_CLI\Maintenance\Replace_Label_Command' );
