<?php

class SolrPower_Api {

	/**
	 * Singleton instance
	 * @var SolrPower_Api|Bool
	 */
	private static $instance = false;

	/**
	 * Logging for debugging.
	 * @var array
	 */
	public $log = array();

	public $solr = null;

	/**
	 * @var string Last response code/exception code.
	 */
	public $last_code;

	/**
	 * @var string Last exception returned.
	 */
	public $last_error;

	/**
	 * Grab instance of object.
	 * @return SolrPower_Api
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	function __construct() {
		add_action( 'admin_notices', array( $this, 'check_for_schema' ) );
	}

	function submit_schema() {
		// Solarium does not currently support submitting schemas to the server.
		// So we'll do it ourselves

		$returnValue = '';
		$upload_dir  = wp_upload_dir();

		// Let's check for a custom Schema.xml. It MUST be located in
		// wp-content/uploads/solr-for-wordpress-on-pantheon/schema.xml
		if ( is_file( realpath( ABSPATH ) . '/' . $_ENV['FILEMOUNT'] . '/solr-for-wordpress-on-pantheon/schema.xml' ) ) {
			$schema = realpath( ABSPATH ) . '/' . $_ENV['FILEMOUNT'] . '/solr-for-wordpress-on-pantheon/schema.xml';
		} else {
			$schema = SOLR_POWER_PATH . '/schema.xml';
		}

		$path        = $this->compute_path();
		$url         = 'https://' . getenv( 'PANTHEON_INDEX_HOST' ) . ':' . getenv( 'PANTHEON_INDEX_PORT' ) . '/' . $path;
		$client_cert = realpath( ABSPATH . '../certs/binding.pem' );

		/*
		 * A couple of quick checks to make sure everything seems sane
		 */
		if ( $errorMessage = SolrPower::get_instance()->sanity_check() ) {
			return $errorMessage;
		}

		if ( ! file_exists( $schema ) ) {
			return $schema . ' does not exist.';
		}

		if ( ! file_exists( $client_cert ) ) {
			return $client_cert . ' does not exist.';
		}


		$file = fopen( $schema, 'r' );
		// set URL and other appropriate options
		$opts = array(
			CURLOPT_URL            => $url,
			CURLOPT_PORT           => 449,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_SSLCERT        => $client_cert,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_HTTPHEADER     => array( 'Content-type:text/xml; charset=utf-8' ),
			CURLOPT_PUT            => true,
			CURLOPT_BINARYTRANSFER => 1,
			CURLOPT_INFILE         => $file,
			CURLOPT_INFILESIZE     => filesize( $schema ),
		);

		$ch = curl_init();
		curl_setopt_array( $ch, $opts );

		$response  = curl_exec( $ch );
		$curl_opts = curl_getinfo( $ch );
		fclose( $file );
		if ( 200 === (int) $curl_opts['http_code'] ) {
			$returnValue = 'Schema Upload Success: ' . $curl_opts['http_code'];
		} else {
			$returnValue = 'Schema Upload Error: ' . $curl_opts['http_code'];
		}

		return $returnValue;
	}

	/**
	 * build the path that the Solr server uses
	 * @return string
	 */
	function compute_path() {
		if ( defined( 'SOLR_PATH' ) ) {
			return SOLR_PATH;
		}

		return '/sites/self/environments/' . getenv( 'PANTHEON_ENVIRONMENT' ) . '/index';
	}

	/**
	 * check if the server by pinging it
	 * @return boolean
	 */
	function ping_server() {
		$solr = get_solr();

		if ( ! $solr ) {
			return false;
		}

		try {
			$ping            = $solr->ping( $solr->createPing() );
			$this->last_code = 200;

			return true;
		} catch ( Solarium\Exception\HttpException $e ) {

			$this->last_code  = $e->getCode();
			$this->last_error = $e;

			return false;
		}
	}

	/**
	 * Connect to the solr service
	 * @return solr service object
	 */
	function get_solr() {

		# get the connection options
		$plugin_s4wp_settings = solr_options();

		/*
		 * Check for the SOLR_POWER_SCHEME constant.
		 * If it exists and is "http" or "https", use it as the default scheme value.
		 */
		$default_scheme = ( defined( 'SOLR_POWER_SCHEME' ) && 1 === preg_match( '/^http[s]?$/', SOLR_POWER_SCHEME ) ) ? SOLR_POWER_SCHEME : 'https';

		$solarium_config = array(
			'endpoint' => array(
				'localhost' => array(
					'host'   => getenv( 'PANTHEON_INDEX_HOST' ),
					'port'   => getenv( 'PANTHEON_INDEX_PORT' ),
					'scheme' => apply_filters( 'solr_scheme', $default_scheme ),
					'path'   => $this->compute_path(),
					'ssl'    => array( 'local_cert' => realpath( ABSPATH . '../certs/binding.pem' ) )
				)
			)
		);

		$solarium_config = apply_filters( 's4wp_connection_options', $solarium_config );


		# double check everything has been set
		if ( ! ( $solarium_config['endpoint']['localhost']['host'] and
		         $solarium_config['endpoint']['localhost']['port'] and
		         $solarium_config['endpoint']['localhost']['path'] )
		) {
			syslog( LOG_ERR, "host, port or path are empty, host:$host, port:$port, path:$path" );

			return null;
		}


		$solr = new Solarium\Client( $solarium_config );

		$solr       = apply_filters( 's4wp_solr', $solr ); // better name?
		$this->solr = $solr;

		return $solr;
	}

	function optimize() {
		try {
			$solr = get_solr();
			if ( ! $solr == null ) {
				$update = $solr->createUpdate();
				$update->addOptimize();
				$solr->update( $update );
			}
		} catch ( Exception $e ) {
			syslog( LOG_ERR, $e->getMessage() );
		}
	}

	/**
	 * Query the required server
	 * passes all parameters to the appropriate function based on the server name
	 * This allows for extensible server/core based query functions.
	 * TODO allow for similar theme/output function
	 */
	function query( $qry, $offset, $count, $fq, $sortby, $order, $server = 'master' ) {
		//NOTICE: does this needs to be cached to stop the db being hit to grab the options everytime search is being done.
		$plugin_s4wp_settings = solr_options();

		$solr = get_solr();

		return $this->master_query( $solr, $qry, $offset, $count, $fq, $sortby, $order, $plugin_s4wp_settings );
	}

	function master_query( $solr, $qry, $offset, $count, $fq, $sortby, $order, &$plugin_s4wp_settings ) {
		$this->add_log( array(
			'Search Query' => $qry,
			'Offset'       => $offset,
			'Count'        => $count,
			'fq'           => $fq,
			'Sort By'      => $sortby,
			'Order'        => $order
		) );


		$response       = null;
		$facet_fields   = array();
		$number_of_tags = $plugin_s4wp_settings['s4wp_max_display_tags'];

		if ( $plugin_s4wp_settings['s4wp_facet_on_categories'] ) {
			$facet_fields[] = 'categories';
		}

		$facet_on_tags = $plugin_s4wp_settings['s4wp_facet_on_tags'];
		if ( $facet_on_tags ) {
			$facet_fields[] = 'tags';
		}

		if ( $plugin_s4wp_settings['s4wp_facet_on_author'] ) {
			$facet_fields[] = 'post_author';
		}

		if ( $plugin_s4wp_settings['s4wp_facet_on_type'] ) {
			$facet_fields[] = 'post_type';
		}


		$facet_on_custom_taxonomy = $plugin_s4wp_settings['s4wp_facet_on_taxonomy'];
		if ( count( $facet_on_custom_taxonomy ) ) {
			$taxonomies = (array) get_taxonomies( array( '_builtin' => false ), 'names' );
			foreach ( $taxonomies as $parent ) {
				$facet_fields[] = $parent . "_taxonomy";
			}
		}

		$facet_on_custom_fields = $plugin_s4wp_settings['s4wp_facet_on_custom_fields'];
		if ( is_array( $facet_on_custom_fields ) and count( $facet_on_custom_fields ) ) {
			foreach ( $facet_on_custom_fields as $field_name ) {
				$facet_fields[] = $field_name . '_str';
			}
		}

		if ( $solr ) {
			$select = array(
				'query'      => $qry,
				'fields'     => '*,score',
				'start'      => $offset,
				'rows'       => $count,
				'omitheader' => false
			);
			if ( $sortby != "" ) {
				$select['sort'] = array( $sortby => $order );
			} else {
				$select['sort'] = array( 'post_date' => 'desc' );
			}

			$query = $solr->createSelect( $select );

			$facetSet = $query->getFacetSet();
			foreach ( $facet_fields as $facet_field ) {
				$facetSet->createFacetField( $facet_field )->setField( $facet_field );
			}
			$facetSet->setMinCount( 1 );
			if ( $facet_on_tags ) {
				$facetSet->setLimit( $number_of_tags );
			}

			if ( isset( $fq ) ) {
				foreach ( $fq as $filter ) {
					if ( $filter !== "" ) {
						$query->createFilterQuery( $filter )->setQuery( $filter );
					}
				}
			}
			$query->getHighlighting()->setFields( 'post_content' );
			$query->getHighlighting()->setSimplePrefix( '<b>' );
			$query->getHighlighting()->setSimplePostfix( '</b>' );
			$query->getHighlighting()->setHighlightMultiTerm( true );

			if ( isset( $plugin_s4wp_settings['s4wp_default_operator'] ) ) {
				$query->setQueryDefaultOperator( $plugin_s4wp_settings['s4wp_default_operator'] );
			}
			try {
				$response = $solr->select( $query );
				if ( ! $response->getResponse()->getStatusCode() == 200 ) {
					$response = null;
				}
			} catch ( Exception $e ) {
				syslog( LOG_ERR, "failed to query solr. " . $e->getMessage() );
				$response = null;
			}
		}


		return $response;
	}

	/**
	 * Add items to debug log.
	 *
	 * @param array $item Array of items.
	 */
	function add_log( $item ) {
		$this->log = array_merge( $this->log, $item );
	}

	/**
	 * Loops through each public post type and returns array of index count.
	 * @return array
	 */
	function index_stats() {
		$cache_key = 'solr_index_stats';
		$stats     = wp_cache_get( $cache_key, 'solr' );
		if ( false === $stats ) {

			$post_types = get_post_types( array( 'exclude_from_search' => false ) );

			$stats = array();
			foreach ( $post_types as $type ) {
				$stats[ $type ] = $this->fetch_stat( $type );
			}

			wp_cache_set( $cache_key, $stats, 'solr', 300 );
		}

		return $stats;
	}

	/**
	 * Queries Solr with specified post_type and returns number found.
	 *
	 * @param $type
	 *
	 * @return int
	 */
	private function fetch_stat( $type ) {
		$qry    = 'post_type:' . $type;
		$offset = 0;
		$count  = 1;
		$fq     = array();
		$sortby = 'score';
		$order  = 'desc';
		$search = $this->query( $qry, $offset, $count, $fq, $sortby, $order );
		if ( is_null( $search ) ) {
			return 0;
		}
		$search = $search->getData();

		$search = $search['response'];

		return $search['numFound'];
	}

	/**
	 * Admin Notice Hook
	 * Checks HTTP status response code to determine schema submission.
	 * Pings Solr, checks exception code, and then submits schema.
	 * Displays error if schema submission fails.
	 */
	function check_for_schema() {
		$last_check = get_transient( 'schema_check' );
		if ( false === $last_check ) {

			if ( getenv( 'PANTHEON_ENVIRONMENT' ) !== false ) {
				// Ping Solr.
				$this->ping_server();

				if ( 404 === $this->last_code ) { // Schema is missing on Pantheon.
					$schemaSubmit = $this->submit_schema();
					if ( strpos( $schemaSubmit, 'Error' ) ) {
						echo '<div class="notice notice-error"><p>';
						echo '<h2>Solr Power Error:</h2>';
						echo 'Error posting schema.xml to ApacheSolr backend, which will prevent content from being indexed. You can try navigating to the Solr Power admin section in the WordPress dashboard to try posting the schema directly. If this problem persists, open a support ticket from you Pantheon site dashboard.';
						echo '</p></div>';
					}
				}
				// Set a transient so we are not checking on every page load.
				set_transient( 'schema_check', '1', 300 );
			}

		}
	}
}

SolrPower_Api::get_instance();

/**
 * Helper function to return Solr object.
 */
function get_solr() {
	return SolrPower_Api::get_instance()->get_solr();
}
