<?php
/**
 * The base class for the forms plugin's agent tools.
 *
 * Structurally identical to the MCP plugin's own base: a tool declares its
 * name, its description, its input schema and its execution, and the
 * definition serialises the schema the way strict MCP clients require. The
 * class is duplicated rather than inherited because the forms plugin depends
 * on nothing in AgentShell — it works on plain WordPress with any theme —
 * and inheriting the transport's base would trade that independence for
 * sharing four lines.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for the twelve form tools.
 */
abstract class Base {
	/**
	 * The tool name as agents call it.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * What the tool does, in one or two sentences.
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * The JSON Schema of the tool's arguments.
	 *
	 * @return array
	 */
	abstract public function get_input_schema();

	/**
	 * Run the tool.
	 *
	 * Every tool returns an array carrying `ok`: true with the documented
	 * payload, or false with a code and a message. A refusal is a decision
	 * and is returned, never thrown.
	 *
	 * @param array $arguments The caller's arguments.
	 * @return array
	 */
	abstract public function execute( array $arguments );

	/**
	 * The MCP tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		$schema = $this->get_input_schema();

		// MCP requires inputSchema.properties to be a JSON object. An empty
		// PHP array encodes as [], which strict clients reject — that
		// malformed response voids the whole tools/list payload, hiding every
		// tool, not just this one. `required` is deliberately left alone:
		// JSON Schema requires it to stay an array, empty or not.
		if ( isset( $schema['properties'] )
			&& is_array( $schema['properties'] )
			&& array() === $schema['properties'] ) {
			$schema['properties'] = (object) array();
		}

		return array(
			'name'        => $this->get_name(),
			'description' => $this->get_description(),
			'inputSchema' => $schema,
		);
	}
}
