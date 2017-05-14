<?php
/**
 * Schema Validator
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2017 (c) Iron Bound Designs.
 * @license     GPLv2
 */

namespace IronBound\WP_REST_API\SchemaValidator;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\Exception\ResourceNotFoundException;
use JsonSchema\SchemaStorage;
use JsonSchema\Uri\Retrievers\PredefinedArray;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Validator;

/**
 * Class Middleware
 *
 * @package IronBound\WP_REST_API\SchemaValidator
 */
class Middleware {

	/** @var string */
	private $namespace;

	/** @var int */
	private $check_mode;

	/** @var array[] */
	private $shared_schemas = array();

	/** @var UriRetriever */
	private $uri_retriever;

	/** @var SchemaStorage */
	private $schema_storage;

	/** @var array[] '/wp/v2/posts' => [ 'GET' => 'posts', 'POST' => 'posts-post', 'PUT' => 'posts' ] */
	private $routes_to_schema_titles = array();

	/**
	 * Middleware constructor.
	 *
	 * @param string $namespace
	 * @param int    $check_mode Check mode. See Constraint class constants.
	 */
	public function __construct( $namespace, $check_mode = 0 ) {
		$this->namespace = trim( $namespace, '/' );

		if ( $check_mode === 0 ) {
			$check_mode = Constraint::CHECK_MODE_NORMAL | Constraint::CHECK_MODE_APPLY_DEFAULTS | Constraint::CHECK_MODE_COERCE_TYPES;
		}

		$this->check_mode = $check_mode;
	}

	/**
	 * Initialize the middleware.
	 *
	 * @since 1.0.0
	 */
	public function initialize() {
		add_filter( 'rest_dispatch_request', array( $this, 'validate_and_conform_request' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'load_schemas' ), 100 );
	}

	/**
	 * Deinitialize the middleware and remove filters.
	 *
	 * @since 1.0.0
	 */
	public function deinitialize() {
		remove_filter( 'rest_dispatch_request', array( $this, 'validate_and_conform_request' ), 10 );
		remove_action( 'rest_api_init', array( $this, 'load_schemas' ), 100 );
	}

	/**
	 * Add a schema that is not attached to a particular route, but can still be referenced by URL.
	 *
	 * @since 1.0.0
	 *
	 * @param array $schema
	 */
	public function add_shared_schema( array $schema ) {
		$this->shared_schemas[] = $schema;
	}

	/**
	 * After the routes have been registered with the REST server, load all of their schemas into schema storage.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Server $server
	 */
	public function load_schemas( \WP_REST_Server $server ) {

		$endpoints = $this->get_endpoints_for_namespace( $server );
		$schemas   = array();
		$titles    = array();

		foreach ( $endpoints as $route => $handlers ) {

			$single_method = false;

			if ( empty( $handlers['schema'] ) ) {
				if ( empty( $handlers[0]['schema'] ) ) {
					continue;
				}

				$default_schema = call_user_func( $handlers[0]['schema'] );
				$single_method  = true;
			} else {
				$default_schema = call_user_func( $handlers['schema'] );
			}

			if ( empty( $default_schema['title'] ) ) {
				continue;
			}

			$default_title       = $default_schema['title'];
			$default_url         = $this->get_url_for_schema( $default_title );
			$default_schema_json = wp_json_encode( $default_schema );

			$titles[ $route ] = array();

			if ( isset( $handlers['callback'] ) ) {
				$handlers = array( $handlers );
			}

			// Allow for different schemas per HTTP Method.
			foreach ( $handlers as $i => $handler ) {
				if ( ! is_int( $i ) ) { // Route option
					continue;
				}

				$methods = is_string( $handler['methods'] ) ? explode( ',', $handler['methods'] ) : $handler['methods'];

				if ( ! $single_method && isset( $handler['schema'] ) ) {
					$method_schema_json = wp_json_encode( call_user_func( $handler['schema'] ) );
				} else {
					$method_schema_json = null;
				}

				foreach ( $methods as $method ) {
					if ( ! $method_schema_json ) {
						$schemas[ $default_url ]     = $default_schema_json;
						$titles[ $route ][ $method ] = $default_title;

						continue;
					}

					$title = $default_title . '-' . strtolower( $method );
					$url   = $this->get_url_for_schema( $title );

					$titles[ $route ][ $method ] = $title;
					$schemas[ $url ]             = $method_schema_json;
				}
			}
		}

		foreach ( $this->shared_schemas as $shared_schema ) {
			$schemas[ $this->get_url_for_schema( $shared_schema['title'] ) ] = wp_json_encode( $shared_schema );
		}

		$strategy            = new PredefinedArray( $schemas );
		$this->uri_retriever = new UriRetriever();
		$this->uri_retriever->setUriRetriever( $strategy );

		$this->schema_storage          = new SchemaStorage( $this->uri_retriever );
		$this->routes_to_schema_titles = $titles;

		$this->register_schema_route();
		$this->disable_auto_core_param_validation( $server, $endpoints );
	}

	/**
	 * Validate a request and conform it to the schema.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Response|null|\WP_Error $response
	 * @param \WP_REST_Request                 $request
	 * @param string                           $route
	 * @param array                            $handler
	 *
	 * @return \WP_REST_Response|null|\WP_Error
	 */
	public function validate_and_conform_request( $response, $request, $route, $handler ) {

		if ( $response !== null ) {
			return $response;
		}

		$method = $request->get_method();

		if ( $method === 'GET' ) {
			$schema_object = json_decode( wp_json_encode( array(
				'type'       => 'object',
				'properties' => $handler['args'],
			) ) );
			$properties    = $handler['args'];
		} else {
			$url           = $this->get_url_for_schema( $this->routes_to_schema_titles[ $route ][ $method ] );
			$schema_object = clone $this->schema_storage->getSchema( $url );

			if ( empty( $schema_object->properties ) ) {
				return $response;
			}

			$properties = get_object_vars( $schema_object->properties );
		}

		$has = $this->make_has_closure( $request );

		$to_validate    = array();
		$types_to_check = $method === 'GET' ? array( 'GET' ) : array( 'JSON', 'POST' );

		foreach ( $properties as $property => $config ) {

			if ( ! empty( $config->readonly ) ) {
				continue;
			}

			if ( $method !== 'POST' && ! empty( $config->createonly ) ) {
				$schema_object->properties->$property->required = false;

				continue;
			}

			if ( $has( $property, $types_to_check ) ) {
				$to_validate[ $property ] = $request[ $property ];
			}
		}

		if ( ! $to_validate ) {
			return $response;
		}

		$validated = $this->validate_params( $to_validate, $schema_object );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$set = $this->make_set_closure( $request );

		foreach ( $validated as $property => $value ) {
			$set( $property, $value );
		}

		return null;
	}

	/**
	 * Validate parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param array     $to_validate
	 * @param \stdClass $schema_object
	 *
	 * @return array|\WP_Error
	 */
	protected function validate_params( $to_validate, $schema_object ) {

		$to_validate = json_decode( wp_json_encode( $to_validate ) );
		$validator   = $this->make_validator();

		$validator->validate( $to_validate, $schema_object );

		if ( $validator->isValid() ) {
			$return = array();

			// Validate may change the request contents based on the check mode.
			foreach ( json_decode( json_encode( $to_validate ), true ) as $prop => $value ) {
				$return[ $prop ] = $value;
			}

			return $return;
		}

		$invalid_params = array();

		foreach ( $validator->getErrors() as $error ) {
			$invalid_params[ $error['property'] ] = $error['message'];
		}

		return new \WP_Error(
			'rest_invalid_param',
			sprintf( __( 'Invalid parameter(s): %s' ), implode( ', ', array_keys( $invalid_params ) ) ),
			array( 'status' => 400, 'params' => $invalid_params )
		);
	}

	/**
	 * Make a Schema validator.
	 *
	 * @since 1.0.0
	 *
	 * @return Validator
	 */
	protected function make_validator() {
		$factory = new Factory(
			$this->schema_storage,
			$this->uri_retriever,
			$this->check_mode
		);

		return new Validator( $factory );
	}

	/**
	 * Get the URL to a schema.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title The 'title' property of the schema.
	 *
	 * @return string
	 */
	public function get_url_for_schema( $title ) {
		return rest_url( "{$this->namespace}/schemas/{$title}" );
	}

	/**
	 * Register the REST Route to show schemas.
	 *
	 * @since 1.0.0
	 */
	protected function register_schema_route() {
		register_rest_route( $this->namespace, 'schemas/(?P<title>[\S+])', array(
			'args'     => array(
				'method' => array(
					'description' => __( 'HTTP method to get the schema for. If not provided, will use the base schema.' ),
					'type'        => 'string',
					'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
				)
			),
			'methods'  => 'GET',
			'callback' => array( $this, 'get_schema_endpoint' )
		) );
	}

	/**
	 * REST endpoint for retrieving a schema.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_schema_endpoint( \WP_REST_Request $request ) {

		$title  = $request['title'];
		$schema = null;

		try {
			$schema = $this->schema_storage->getSchema( $this->get_url_for_schema( $title ) );

			if ( $request['method'] ) {
				$title  .= '-' . strtolower( $request['method'] );
				$schema = $this->schema_storage->getSchema( $this->get_url_for_schema( $title ) );
			}
		} catch ( ResourceNotFoundException $e ) {

			if ( ! $schema ) {
				return new \WP_Error( 'schema_not_found', __( 'Schema not found.' ), array( 'status' => \WP_Http::NOT_FOUND ) );
			}
		}

		return new \WP_REST_Response( $schema );
	}

	/**
	 * By default, WordPress core applies its own minimial attempt at sanitizing and validating according to JSON
	 * schema. We need to override this by setting the callbacks to false.
	 *
	 * Existing validation and sanitation callbacks will be preserved.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Server $server
	 * @param array           $endpoints
	 */
	protected function disable_auto_core_param_validation( \WP_REST_Server $server, array $endpoints ) {

		foreach ( $endpoints as $i => $handlers ) {

			if ( isset( $handlers['callback'] ) ) {
				$endpoints[ $i ] = $this->set_default_callbacks_for_handler( $handlers );

				continue;
			}

			foreach ( $handlers as $j => $handler ) {
				if ( is_int( $j ) ) {
					$endpoints[ $i ][ $j ] = $this->set_default_callbacks_for_handler( $handler );
				}
			}
		}

		\Closure::bind( function ( $server, array $endpoints ) {

			foreach ( $endpoints as $route => $handlers ) {
				$server->endpoints[ $route ] = $handlers;
			}
		}, null, $server )( $server, $endpoints );
	}

	/**
	 * Set the validate and sanitize callbacks to false if not set to disable WP's default validation.
	 *
	 * @since 1.0.0
	 *
	 * @param array $handler
	 *
	 * @return array
	 */
	private function set_default_callbacks_for_handler( array $handler ) {

		if ( empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
			return $handler;
		}

		foreach ( $handler['args'] as $i => $arg ) {

			if ( empty( $arg['validate_callback'] ) || $arg['validate_callback'] === 'rest_validate_request_arg' ) {
				$arg['validate_callback'] = false;
			}

			if ( empty( $arg['sanitize_callback'] ) || $arg['sanitize_callback'] === 'rest_sanitize_request_arg' ) {
				$arg['sanitize_callback'] = false;
			}

			$handler['args'][ $i ] = $arg;
		}

		return $handler;
	}

	/**
	 * Get all endpoint configurations for this namespace.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Server $server
	 *
	 * @return array
	 */
	protected function get_endpoints_for_namespace( \WP_REST_Server $server ) {

		$namespace = '/' . $this->namespace;

		$endpoints = $this->get_endpoint_configs( $server );
		$endpoints = array_filter( $endpoints, function ( $route ) use ( $namespace ) {
			return strpos( $route, $namespace ) === 0;
		}, ARRAY_FILTER_USE_KEY );

		return $endpoints;
	}

	/**
	 * Get the configuration that routes were registered with.
	 *
	 * The REST server does not provide any introspection to this data without doing a lot of heavy processing.
	 * This is the easiest way to get access to the raw data without abusing filters or causing a performance hit.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Server $server
	 *
	 * @return array
	 */
	protected function get_endpoint_configs( \WP_REST_Server $server ) {
		return \Closure::bind( function ( $server ) {
			return $server->endpoints;
		}, null, $server )( $server );
	}

	/**
	 * Make a callable scoped to the WP_Rest_Request object to be able to properly determine if a request has a given
	 * param.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \Closure
	 */
	private function make_has_closure( \WP_REST_Request $request ) {
		return \Closure::bind( function ( $key, array $param_types_to_check ) {
			$order = $this->get_parameter_order();

			if ( $param_types_to_check ) {
				$order = array_intersect( $order, $param_types_to_check );
			}

			if ( ( $i = array_search( 'defaults', $order, true ) ) !== false ) {
				unset( $order[ $i ] );
			}

			foreach ( $order as $type ) {
				if ( ! isset( $this->params[ $type ] ) ) {
					continue;
				}

				if ( array_key_exists( $key, $this->params[ $type ] ) ) {
					return true;
				}
			}

			return false;
		}, $request, $request );
	}

	/**
	 * Make a callable scoped to the WP_REST_Request object to be able to properly set a value in the request object
	 * while following parameter order. See https://core.trac.wordpress.org/ticket/40344
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \Closure
	 */
	private function make_set_closure( \WP_REST_Request $request ) {
		return \Closure::bind( function ( $key, $value ) {
			$order = $this->get_parameter_order();
			$first = reset( $order );

			$this->params[ $first ][ $key ] = $value;
		}, $request, $request );
	}

	/**
	 * Convert an array to an object.
	 *
	 * @since 1.0.0
	 *
	 * @param array $array
	 *
	 * @return \stdClass
	 */
	private static function array_to_object( array $array ) {
		$obj = new \stdClass;

		foreach ( $array as $k => $v ) {
			if ( is_array( $v ) ) {
				$obj->{$k} = self::array_to_object( $v );
			} elseif ( $k !== '' ) {
				$obj->{$k} = $v;
			}
		}

		return $obj;
	}
}