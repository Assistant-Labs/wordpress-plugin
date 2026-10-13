/* AssistantLabs admin: confirm destructive or live actions. */
( function () {
	'use strict';

	var messages = window.assistantlabsAdmin || {};
	var keys = {
		discard: 'confirmDiscard',
		disconnect: 'confirmDisconnect',
		publish: 'confirmPublish',
	};

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.getAttribute ) {
			return;
		}
		var kind = form.getAttribute( 'data-assistantlabs-confirm' );
		var message = kind && messages[ keys[ kind ] ];
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} )();
