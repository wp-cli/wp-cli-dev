<?php
/**
 * phpstorm follows symlinks when indexing, which creates an infinite loop of indexing.
 * The inclusion of the vendor folders is mostly the problem
 */

class phpstorm_exclude_recursive_folders {

	public static function init() {
		self::has_dot_idea_folder();
		self::has_modules_xml();
		self::has_project_iml();
		self::set_exclude_in_project_iml();
	}

	/**
	 * Check if .idea folder exists, if not create it.
	 */
	protected static function has_dot_idea_folder() {
		// Has .idea folder?
		if ( ! is_dir( '.idea' ) ) {
			mkdir( '.idea' );
		}
	}

	/**
	 * Check if .idea/modules.xml exists, if not create it.
	 */
	protected static function has_modules_xml() {
		// Does the modules.xml file exists?
		if ( is_file( '.idea/modules.xml' ) ) {
			return; // already exists
		}
		$project_name = self::get_project_name();

		$modules_xml_content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<project version="4">
	<component name="ProjectModuleManager">
		<modules>
			<module fileurl="file://\$PROJECT_DIR\$/.idea/{$project_name}.iml" filepath="\$PROJECT_DIR\$/.idea/{$project_name}.iml" />
		</modules>
	</component>
</project>
XML;
		file_put_contents( '.idea/modules.xml', $modules_xml_content );
	}

	/**
	 * Check if .idea/PROJECT.iml exists, if not create it.
	 */
	protected static function has_project_iml() {
		// does the .iml file exists?
		if ( is_file( self::get_project_iml_path() ) ) {
			return; // already exists.
		}
		$modules_xml_content = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<module type="WEB_MODULE" version="4">
	<component name="NewModuleRootManager">
		<content url="file://\$MODULE_DIR\$"/>
		<orderEntry type="inheritedJdk" />
		<orderEntry type="sourceFolder" forTests="false" />
	</component>
</module>
XML;
		file_put_contents( self::get_project_iml_path(), $modules_xml_content );
	}

	/**
	 * Get the name of the project, by default it's the same as the folder name.
	 *
	 * @return false|string
	 */
	protected static function get_project_name() {
		$pwd          = getcwd();
		$project_name = substr( $pwd, strrpos( $pwd, "/" ) + 1 );

		return $project_name;
	}

	/**
	 * Get the project.iml file path.
	 * @return string
	 */
	protected static function get_project_iml_path() {
		$modules_xml = new DOMDocument();
		$modules_xml->load( '.idea/modules.xml' );
		$iml_file_path = $modules_xml->getElementsByTagName( 'component' )->item( 0 )
		                             ->getElementsByTagName( 'modules' )->item( 0 )
		                             ->getElementsByTagName( 'module' )->item( 0 )->getAttribute( 'filepath' );
		$iml_file_path = str_replace( '$PROJECT_DIR$/', '', $iml_file_path );

		return $iml_file_path;
	}

	/**
	 * Generate the list of the folders to exclude.
	 *
	 * @return array
	 */
	protected static function get_exclude_dir_list() {
		$command_folders = glob( '*', GLOB_ONLYDIR );
		$exclude_folders = array();
		foreach ( $command_folders as $command_folder ) {
			if ( ! is_dir( "{$command_folder}/vendor" ) ) {
				continue;
			}
			$exclude_folders[] = "{$command_folder}/vendor";
		}

		// hard code, always exclude the vendor/wp-cli, it's only symlinks.
		$exclude_folders[] = 'vendor/wp-cli';
		$exclude_folders[] = 'builds/phar';

		return $exclude_folders;
	}

	/**
	 * Add the folders to exclude in the iml file.
	 */
	protected static function set_exclude_in_project_iml() {
		$folders_to_exclude          = self::get_exclude_dir_list();
		$iml_xml                     = new DOMDocument();
		$iml_xml->preserveWhiteSpace = false;
		$iml_xml->formatOutput       = true;
		$iml_xml->load( self::get_project_iml_path() );
		$iml_xml_content_node = $iml_xml->getElementsByTagName( 'component' )->item( 0 )
		                                ->getElementsByTagName( 'content' )->item( 0 );
		$xpath                = new DomXpath( $iml_xml );

		foreach ( $folders_to_exclude as $folder ) {
			$attributevalue = "file://\$MODULE_DIR\$/{$folder}";

			// Check for duplicates.
			$duplicates = $xpath->query( '//excludeFolder[@url="' . $attributevalue . '"]' );
			if ( 0 !== $duplicates->length ) {
				continue; // Don't add duplicates.
			}

			// Add child element.
			$exclude_node = $iml_xml->createElement( 'excludeFolder' );
			$exclude_node->setAttribute( 'url', $attributevalue );
			$iml_xml_content_node->appendChild( $exclude_node );
		}

		$iml_xml->preserveWhiteSpace = false;
		$iml_xml->formatOutput       = true;
		$iml_xml->save( self::get_project_iml_path() );
	}
}

/**
 * GO!
 */
phpstorm_exclude_recursive_folders::init();
