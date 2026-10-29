<?php
/**
 * The renderer's markup, escaping and accessibility contract — no database.
 *
 * The renderer's public half is a pure function of a definition document, so
 * every property that can be proved without a WordPress install is proved here:
 * one control per field type, a label for every control, group semantics, the
 * required marker, the hint text, the escaping of everything the operator
 * authored, per-instance ids, the empty challenge input, the honeypot, the error
 * summary, and the published-only selection rule.
 *
 * What is deliberately *not* here: the shortcode, the widget and the REST route.
 * Those need a real WordPress (`tests/forms-render-live-test.php`), and testing
 * them through stubs would prove only that the stubs work.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/forms-render-fixtures.php';

use AgentShell_Forms\Form_Renderer;
use AgentShell_Forms\Form_Schema;

echo "forms renderer (markup, escaping, accessibility)\n";

/**
 * Whether a string contains a substring.
 *
 * @param string $haystack Haystack.
 * @param string $needle   Needle.
 * @return bool
 */
function asf_has( $haystack, $needle ) {
	return false !== strpos( (string) $haystack, (string) $needle );
}

/**
 * Every `id` attribute in a document.
 *
 * @param string $html Markup.
 * @return array<int,string>
 */
function asf_ids( $html ) {
	preg_match_all( '/\sid="([^"]*)"/', (string) $html, $m );

	return $m[1];
}

/**
 * Every `for` attribute in a document.
 *
 * @param string $html Markup.
 * @return array<int,string>
 */
function asf_fors( $html ) {
	preg_match_all( '/\sfor="([^"]*)"/', (string) $html, $m );

	return $m[1];
}

/**
 * The attributes of the first tag matching a pattern.
 *
 * @param string $html    Markup.
 * @param string $pattern Tag pattern.
 * @return string The tag's source, or ''.
 */
function asf_tag( $html, $pattern ) {
	if ( preg_match( $pattern, (string) $html, $m ) ) {
		return $m[0];
	}

	return '';
}

Agentshell_Test::run(
	'the form element carries the published identity and the submission route',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );
		$tag  = asf_tag( $html, '/<form\b[^>]*>/' );

		Agentshell_Test::assert_true( '' !== $tag, 'a form element must be rendered' );
		Agentshell_Test::assert_true( asf_has( $tag, 'method="post"' ), 'the form must be a POST form' );
		Agentshell_Test::assert_true(
			asf_has( $tag, 'data-form-id="7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a"' ),
			'the form must carry the published internal identifier'
		);
		Agentshell_Test::assert_true( asf_has( $tag, 'data-form-version="3"' ), 'the form must carry the published version' );
		Agentshell_Test::assert_true( asf_has( $tag, 'data-challenge-endpoint="' ), 'the form must name the challenge route' );
		Agentshell_Test::assert_true(
			asf_has( $tag, 'action="' . \AgentShell_Forms\Submission_Handler::submit_url() . '"' ),
			'the form must point at the submission route for the no-script fallback'
		);
		Agentshell_Test::assert_true(
			asf_has( $tag, 'data-submit-endpoint="' . \AgentShell_Forms\Submission_Handler::submit_url() . '"' ),
			'the script must be given the submission route'
		);
		Agentshell_Test::assert_false(
			asf_has( $tag, 'novalidate' ),
			'novalidate would remove the browser validation a visitor relies on'
		);
	}
);

Agentshell_Test::run(
	'each of the ten field types renders its native control',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		// The single-control types, addressed by their own id so the assertion
		// does not depend on the order attributes happen to be emitted in.
		$singles = array(
			'text'     => array( 'full_name', 'type="text"' ),
			'email'    => array( 'email', 'type="email"' ),
			'tel'      => array( 'phone', 'type="tel"' ),
			'number'   => array( 'budget', 'type="number"' ),
			'textarea' => array( 'message', '<textarea' ),
			'select'   => array( 'topic', '<select' ),
		);

		foreach ( $singles as $type => $expectation ) {
			$tag = asf_tag( $html, '/<(?:input|textarea|select)\b[^>]*id="[^"]*-' . preg_quote( $expectation[0], '/' ) . '"[^>]*>/' );

			Agentshell_Test::assert_true( '' !== $tag, "the {$type} field did not render a control" );
			Agentshell_Test::assert_true( asf_has( $tag, $expectation[1] ), "the {$type} control is not a {$expectation[1]}: {$tag}" );
		}

		// The grouped types and the two specials.
		Agentshell_Test::assert_true(
			(bool) preg_match( '/<input [^>]*type="radio"[^>]*>/', $html ),
			'the radio options must be radio inputs'
		);
		Agentshell_Test::assert_true(
			(bool) preg_match( '/<input [^>]*type="checkbox"[^>]*name="fields\[topics\]\[\]"/', $html ),
			'the checkbox options must be checkbox inputs'
		);
		Agentshell_Test::assert_true(
			(bool) preg_match( '/<input (?=[^>]*type="checkbox")(?=[^>]*name="fields\[consent\]")[^>]*>/', $html ),
			'the consent field must be a single checkbox'
		);
		Agentshell_Test::assert_true(
			(bool) preg_match( '/<input type="hidden" [^>]*id="[^"]*-source"/', $html ),
			'the hidden field must not be a visible control'
		);

		// Every field type is also declared on its wrapper, so CSS and Phase 3's
		// error re-render can address a type without parsing the control.
		foreach ( array( 'text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'consent', 'hidden' ) as $type ) {
			Agentshell_Test::assert_true(
				asf_has( $html, 'data-field-type="' . $type . '"' ),
				"the {$type} field wrapper is missing its type"
			);
		}
	}
);

Agentshell_Test::run(
	'the number field declares its bounds and its step',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );
		$tag  = asf_tag( $html, '/<input[^>]*type="number"[^>]*>/' );

		Agentshell_Test::assert_true( asf_has( $tag, 'min="1"' ), 'min must carry through' );
		Agentshell_Test::assert_true( asf_has( $tag, 'max="250"' ), 'max must carry through' );
		Agentshell_Test::assert_true( asf_has( $tag, 'step="0.1"' ), 'one decimal place means step 0.1, got: ' . $tag );
	}
);

Agentshell_Test::run(
	'every label points at a control that exists, and every control has one',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );
		$ids  = asf_ids( $html );
		$fors = asf_fors( $html );

		Agentshell_Test::assert_true( count( $fors ) >= 8, 'expected a label for every non-group field' );

		foreach ( $fors as $for ) {
			Agentshell_Test::assert_true( in_array( $for, $ids, true ), "label for=\"{{$for}}\" has no control with that id" );
		}

		// Ids are unique across one render, or the second form's label resolves
		// to the first form's control.
		Agentshell_Test::assert_equals( count( $ids ), count( array_unique( $ids ) ), 'ids must be unique within one render' );
	}
);

Agentshell_Test::run(
	'radio and checkbox groups are fieldsets with a legend',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_equals( 2, substr_count( $html, '<fieldset' ), 'exactly two groups: the radio and the checkbox' );
		Agentshell_Test::assert_equals( 2, substr_count( $html, '<legend' ), 'each group needs a legend' );
		Agentshell_Test::assert_true(
			asf_has( $html, '<legend class="agentshell-forms__label" id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-contact_method-legend">How should we reply?' ),
			'the radio group legend must be the field label'
		);

		// The radios share one name, so the browser treats them as one group and
		// arrow keys move between them.
		Agentshell_Test::assert_equals(
			2,
			substr_count( $html, 'name="fields[contact_method]"' ),
			'both radios must share the field name'
		);
	}
);

Agentshell_Test::run(
	'a checkbox group submits an array and keeps its labels associated',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_equals(
			3,
			substr_count( $html, 'name="fields[topics][]"' ),
			'a checkbox group must submit an array, one entry per box'
		);

		preg_match_all( '/<input class="agentshell-forms__checkbox"[^>]*id="([^"]+)"/', $html, $m );
		$ids = $m[1];

		Agentshell_Test::assert_equals( 3, count( $ids ), 'three checkboxes' );

		foreach ( $ids as $id ) {
			Agentshell_Test::assert_true(
				asf_has( $html, 'for="' . $id . '"' ),
				"checkbox {$id} has no label"
			);
		}
	}
);

Agentshell_Test::run(
	'required fields are marked in words and by attribute',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true(
			asf_has( $html, 'Your name <span class="agentshell-forms__required">(required)</span>' ),
			'the required marker must be text a screen reader can read'
		);
		Agentshell_Test::assert_true(
			asf_has( $html, '<input type="text" class="agentshell-forms__control" id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name" name="fields[full_name]" required' )
			|| preg_match( '/<input[^>]*id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name"[^>]*\brequired\b/', $html ),
			'the control must carry the required attribute'
		);
		Agentshell_Test::assert_true(
			asf_has( $html, 'Phone</label>' ),
			'an optional field must not claim to be required'
		);
	}
);

Agentshell_Test::run(
	'hints state the rules in words next to the control',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true( asf_has( $html, 'Up to 80 characters.' ), 'the text maximum must be stated' );
		Agentshell_Test::assert_true( asf_has( $html, 'At least 2 characters.' ), 'the text minimum must be stated' );
		Agentshell_Test::assert_true( asf_has( $html, 'A number from 1 to 250.' ), 'the number range must be stated' );
		Agentshell_Test::assert_true( asf_has( $html, 'Choose one of the options.' ), 'a required group must say a choice is expected' );
	}
);

Agentshell_Test::run(
	'every hint and description is wired to its control by aria-describedby',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true(
			asf_has(
				$html,
				'id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name-description"'
			),
			'the description needs an id to be referenced by'
		);
		Agentshell_Test::assert_true(
			(bool) preg_match(
				'/<input[^>]*id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name"[^>]*aria-describedby="[^"]*full_name-description[^"]*full_name-hint[^"]*"/',
				$html
			),
			'the control must reference its description and its hint'
		);
	}
);

Agentshell_Test::run(
	'operator-authored text is escaped everywhere it lands',
	function () {
		$definition = agentshell_forms_fixture_definition();
		$evil       = '<script>alert("x")</script>&"\'';

		$definition['name']                                   = $evil;
		$definition['description']                            = $evil;
		$definition['fields'][0]['label']                     = $evil;
		$definition['fields'][0]['description']               = $evil;
		$definition['fields'][0]['placeholder']               = $evil;
		$definition['fields'][5]['options'][0]['label']       = $evil;
		$definition['fields'][6]['options'][0]['label']       = $evil;
		$definition['fields'][7]['options'][0]['label']       = $evil;
		$definition['settings']['submit_label']               = $evil;

		$html = Form_Renderer::render_definition( $definition, array( 'instance' => 1 ) );

		Agentshell_Test::assert_false( asf_has( $html, '<script' ), 'no operator string may become a script element' );
		Agentshell_Test::assert_true( asf_has( $html, '&lt;script&gt;' ), 'the marker must survive as text' );
		Agentshell_Test::assert_true( asf_has( $html, '&amp;' ), 'an ampersand must be escaped' );
		Agentshell_Test::assert_true( asf_has( $html, '&quot;' ), 'a double quote must be escaped' );
		Agentshell_Test::assert_true( asf_has( $html, '&#039;' ), 'a single quote must be escaped' );

		// A quote in a placeholder must not break out of the attribute.
		Agentshell_Test::assert_false(
			(bool) preg_match( '/placeholder="[^"]*"[^ >]/', $html ),
			'an attribute value must not be closed by its own content'
		);
	}
);

Agentshell_Test::run(
	'a hidden field takes its value from the definition, never from the request',
	function () {
		$html = Form_Renderer::render_definition(
			agentshell_forms_fixture_definition(),
			array(
				'instance' => 1,
				'values'   => array( 'source' => 'attacker-controlled' ),
			)
		);

		Agentshell_Test::assert_true( asf_has( $html, 'value="contact-page"' ), 'the definition value must be rendered' );
		Agentshell_Test::assert_false( asf_has( $html, 'attacker-controlled' ), 'a supplied value must not replace it' );
	}
);

Agentshell_Test::run(
	'supplied values re-populate every control type',
	function () {
		$html = Form_Renderer::render_definition(
			agentshell_forms_fixture_definition(),
			array(
				'instance' => 1,
				'values'   => array(
					'full_name'      => 'Ada <Lovelace>',
					'email'          => 'ada@example.test',
					'phone'          => '+44 20 7946 0000',
					'budget'         => '12.5',
					'message'        => "Line one\nLine two",
					'topic'          => 'support',
					'contact_method' => 'phone',
					'topics'         => array( 'design', 'research' ),
					'consent'        => true,
				),
			)
		);

		Agentshell_Test::assert_true( asf_has( $html, 'value="Ada &lt;Lovelace&gt;"' ), 'the text value must be re-populated and escaped' );
		Agentshell_Test::assert_true( asf_has( $html, 'value="ada@example.test"' ), 'the email value must be re-populated' );
		Agentshell_Test::assert_true( asf_has( $html, ">Line one\nLine two</textarea>" ), 'the textarea body must be re-populated' );
		Agentshell_Test::assert_true( asf_has( $html, '<option value="support" selected' ), 'the selected option must be marked' );
		Agentshell_Test::assert_true(
			(bool) preg_match( '/<input class="agentshell-forms__radio" type="radio"[^>]*value="phone"[^>]*checked/', $html ),
			'the chosen radio must be checked'
		);
		Agentshell_Test::assert_equals( 2, preg_match_all( '/name="fields\[topics\]\[\]"[^>]*checked/', $html ), 'two boxes were chosen' );
		Agentshell_Test::assert_true(
			(bool) preg_match( '/class="agentshell-forms__control agentshell-forms__consent"[^>]*checked/', $html ),
			'the consent box must be checked'
		);
	}
);

Agentshell_Test::run(
	'errors produce a summary with links and a per-field message',
	function () {
		$html = Form_Renderer::render_definition(
			agentshell_forms_fixture_definition(),
			array(
				'instance' => 1,
				'errors'   => array(
					'full_name' => 'Please tell us your name.',
					'message'   => 'Please write a little more.',
				),
			)
		);

		Agentshell_Test::assert_true( asf_has( $html, 'role="alert"' ), 'the summary must be announced' );
		Agentshell_Test::assert_true( asf_has( $html, 'href="#agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name"' ), 'the summary must link to the field' );
		Agentshell_Test::assert_true( asf_has( $html, 'Please tell us your name.' ), 'the message must be rendered' );
		Agentshell_Test::assert_true(
			asf_has( $html, 'id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name-error"' ),
			'the field-level error needs an id so it can be referenced'
		);
		Agentshell_Test::assert_true(
			(bool) preg_match( '/id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name"[^>]*aria-invalid="true"/', $html ),
			'the invalid control must say so to assistive technology'
		);
		Agentshell_Test::assert_true(
			(bool) preg_match( '/id="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-full_name"[^>]*aria-describedby="[^"]*full_name-error"/', $html ),
			'the control must reference its error'
		);
	}
);

Agentshell_Test::run(
	'an error-free render carries a hidden summary that Phase 3 can fill',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true(
			(bool) preg_match( '/<div class="agentshell-forms__summary" id="[^"]*-summary" tabindex="-1" role="alert" hidden><\/div>/', $html ),
			'the summary container must exist, be focusable and be hidden while empty'
		);
	}
);

Agentshell_Test::run(
	'two instances of the same form have different ids and the same names',
	function () {
		$first  = Form_Renderer::render_definition( agentshell_forms_fixture_definition() );
		$second = Form_Renderer::render_definition( agentshell_forms_fixture_definition() );

		$first_ids  = asf_ids( $first );
		$second_ids = asf_ids( $second );

		Agentshell_Test::assert_true( count( $first_ids ) > 5, 'sanity: the first render has ids' );
		Agentshell_Test::assert_equals(
			array(),
			array_values( array_intersect( $first_ids, $second_ids ) ),
			'no id may be shared between two instances on one page'
		);

		// Names must match, because the submission protocol reads them and two
		// instances of one form must submit the same shape.
		Agentshell_Test::assert_equals(
			2,
			substr_count( $first, 'name="fields[contact_method]"' ),
			'the first instance keeps its names'
		);
		Agentshell_Test::assert_equals(
			substr_count( $first, 'name="fields[contact_method]"' ),
			substr_count( $second, 'name="fields[contact_method]"' ),
			'the second instance keeps the same names'
		);
	}
);

Agentshell_Test::run(
	'the challenge input is empty and no per-visitor value is emitted',
	function () {
		$first  = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );
		$second = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true(
			asf_has( $first, 'name="agentshell_form_challenge" value="" data-agentshell-forms-challenge' ),
			'the challenge must be rendered empty'
		);

		// Byte-identical output is what makes the page cacheable: a nonce, a
		// timestamp or a token would make every render different.
		Agentshell_Test::assert_equals( $first, $second, 'two renders of one version must be byte-identical' );
	}
);

Agentshell_Test::run(
	'the honeypot is invisible, unreachable and hidden from assistive technology',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true( asf_has( $html, 'class="agentshell-forms__hp" aria-hidden="true"' ), 'the trap must be hidden from AT' );
		Agentshell_Test::assert_true( asf_has( $html, 'name="agentshell_forms_hp"' ), 'the trap must have the protocol name' );
		Agentshell_Test::assert_true( asf_has( $html, 'tabindex="-1"' ), 'the trap must not be reachable by keyboard' );
		Agentshell_Test::assert_true( asf_has( $html, 'autocomplete="off"' ), 'the trap must not be autofilled' );
	}
);

Agentshell_Test::run(
	'a form that asks for no honeypot renders none',
	function () {
		$definition = agentshell_forms_fixture_definition();
		$definition['settings']['spam']['honeypot'] = false;

		$html = Form_Renderer::render_definition( $definition, array( 'instance' => 1 ) );

		Agentshell_Test::assert_false( asf_has( $html, 'agentshell-forms__hp' ), 'the trap is opt-out' );
	}
);

Agentshell_Test::run(
	'the status region exists and is polite before it has anything to say',
	function () {
		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		Agentshell_Test::assert_true(
			asf_has( $html, 'role="status" aria-live="polite" data-agentshell-forms-status' ),
			'a success message needs a live region that already exists'
		);
	}
);

Agentshell_Test::run(
	'the published definition is selected, and the working one is never read',
	function () {
		$working = agentshell_forms_fixture_definition();
		$working['name'] = 'WORKING DRAFT NAME';

		$published = agentshell_forms_fixture_definition();
		$published['name'] = 'PUBLISHED NAME';

		$selected = Form_Renderer::select_published(
			array(
				'post_id'   => 10,
				'form_id'   => '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
				'slug'      => 'contact',
				'working'   => $working,
				'published' => $published,
				'version'   => 3,
			)
		);

		Agentshell_Test::assert_true( is_array( $selected ), 'a published definition must be selected' );
		Agentshell_Test::assert_equals( 'PUBLISHED NAME', $selected['name'], 'the published name must win' );

		$html = Form_Renderer::render_definition( $selected, array( 'instance' => 1 ) );
		Agentshell_Test::assert_false( asf_has( $html, 'WORKING DRAFT NAME' ), 'uncommitted work must never reach the page' );
	}
);

Agentshell_Test::run(
	'a form with no published definition is refused, not rendered from the draft',
	function () {
		$refused = Form_Renderer::select_published(
			array(
				'post_id'   => 10,
				'form_id'   => '7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a',
				'slug'      => 'contact',
				'working'   => agentshell_forms_fixture_definition(),
				'published' => null,
			)
		);

		Agentshell_Test::assert_true( is_wp_error( $refused ), 'an unpublished form must be refused' );
		Agentshell_Test::assert_equals( 'form_not_published', $refused->get_error_code(), 'the refusal must be named' );
	}
);

Agentshell_Test::run(
	'the rendering arguments are honoured and bounded',
	function () {
		$html = Form_Renderer::render_definition(
			agentshell_forms_fixture_definition(),
			array(
				'instance'      => 7,
				'title'         => 'hide',
				'heading_level' => 4,
				'class'         => 'my-form  wide"><script>',
			)
		);

		Agentshell_Test::assert_false( asf_has( $html, 'agentshell-forms__title' ), 'title="hide" must omit the heading' );
		Agentshell_Test::assert_false( asf_has( $html, '<script' ), 'a class attribute must not become markup' );
		Agentshell_Test::assert_true( asf_has( $html, 'agentshell-forms--instance-7' ), 'the instance class must be applied' );
		Agentshell_Test::assert_true( asf_has( $html, 'my-form' ), 'a valid class must survive' );

		$shown = Form_Renderer::render_definition(
			agentshell_forms_fixture_definition(),
			array(
				'instance'      => 8,
				'heading_level' => 99,
			)
		);

		Agentshell_Test::assert_true( asf_has( $shown, '<h2 class="agentshell-forms__title"' ), 'an out-of-range level falls back to h2' );

		$h4 = Form_Renderer::render_definition(
			agentshell_forms_fixture_definition(),
			array(
				'instance'      => 9,
				'heading_level' => 4,
			)
		);

		Agentshell_Test::assert_true( asf_has( $h4, '<h4 class="agentshell-forms__title"' ), 'a valid level is honoured' );
	}
);

Agentshell_Test::run(
	'a filter can replace one field control without replacing the wrapper',
	function () {
		$GLOBALS['_agentshell_test_filters']['agentshell_forms_render_field'] = array(
			function ( $markup, $field, $id ) {
				if ( 'budget' !== $field['key'] ) {
					return $markup;
				}

				return '<input type="range" id="' . esc_attr( $id ) . '" name="fields[' . esc_attr( $field['key'] ) . ']">';
			},
		);

		$html = Form_Renderer::render_definition( agentshell_forms_fixture_definition(), array( 'instance' => 1 ) );

		unset( $GLOBALS['_agentshell_test_filters']['agentshell_forms_render_field'] );

		Agentshell_Test::assert_true( asf_has( $html, 'type="range"' ), 'the filter must replace the control' );
		Agentshell_Test::assert_true( asf_has( $html, 'data-field-key="budget"' ), 'the wrapper must survive' );
		Agentshell_Test::assert_true( asf_has( $html, '<label class="agentshell-forms__label" for="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-budget">' ), 'the label must survive' );
	}
);

Agentshell_Test::run(
	'a field type with no renderer falls back to a usable text control',
	function () {
		$definition = agentshell_forms_fixture_definition();
		$definition['fields'] = array(
			array(
				'key'      => 'mystery',
				'type'     => 'not_a_registered_type',
				'label'    => 'Mystery',
				'required' => false,
				'settings' => array(),
			),
		);

		$html = Form_Renderer::render_definition( $definition, array( 'instance' => 1 ) );

		Agentshell_Test::assert_true( asf_has( $html, 'type="text"' ), 'an unknown type must still render a control' );
		Agentshell_Test::assert_true( asf_has( $html, 'for="agentshell-form-7c1d2e3a4b5c6d7e8f9a0b1c2d3e4f5a-1-mystery"' ), 'and keep its label' );
	}
);

Agentshell_Test::run(
	'the diagnostic is invisible to a visitor and visible to a form manager',
	function () {
		$error = new WP_Error( 'form_not_found', 'No form has the slug “nope”.' );

		$GLOBALS['_agentshell_test_user_caps'] = array();
		$visitor = Form_Renderer::render_diagnostic( $error );

		$GLOBALS['_agentshell_test_user_caps'] = array( 'manage_agentshell_forms' => true );
		$editor = Form_Renderer::render_diagnostic( $error );

		$GLOBALS['_agentshell_test_user_caps'] = array();

		Agentshell_Test::assert_equals( '', $visitor, 'a visitor must see nothing' );
		Agentshell_Test::assert_true( asf_has( $editor, 'data-agentshell-forms-diagnostic="form_not_found"' ), 'an editor must see the reason' );
		Agentshell_Test::assert_true( defined( 'DONOTCACHEPAGE' ), 'a diagnostic must mark the request uncacheable' );
	}
);

Agentshell_Test::run(
	'a definition with no fields renders nothing at all',
	function () {
		$empty = agentshell_forms_fixture_definition();
		$empty['fields'] = array();

		Agentshell_Test::assert_equals( '', Form_Renderer::render_definition( $empty ), 'an empty form is not a form' );
	}
);

Agentshell_Test::summary();
