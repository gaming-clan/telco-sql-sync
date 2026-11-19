( function () {
	'use strict';
	var btn = document.getElementById( 'wcsi-go' );
	var fill = document.getElementById( 'wcsi-fill' );
	var status = document.getElementById( 'wcsi-status' );
	var logEl = document.getElementById( 'wcsi-log' );
	if ( ! btn || ! window.WCSI ) { return; }
	var running = false;

	function step() {
		var body = new URLSearchParams( { action: 'wcsi_batch', nonce: WCSI.nonce, job: WCSI.job } );
		fetch( WCSI.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res.success ) { throw new Error( res.data || 'Request failed' ); }
				var d = res.data;
				fill.style.width = d.progress + '%';
				status.textContent = d.progress + '% — created ' + d.created + ', updated ' + d.updated + ', skipped ' + d.skipped + ', failed ' + d.failed;
				if ( d.log.length ) {
					logEl.textContent += d.log.join( '\n' ) + '\n';
					logEl.scrollTop = logEl.scrollHeight;
				}
				if ( d.done ) {
					status.textContent += ' — finished.';
					btn.textContent = 'Done';
				} else {
					step();
				}
			} )
			.catch( function ( e ) {
				running = false;
				btn.disabled = false;
				btn.textContent = 'Resume import';
				status.textContent = 'Stopped: ' + e.message + ' (click Resume to continue where it left off)';
			} );
	}

	btn.addEventListener( 'click', function () {
		if ( running ) { return; }
		running = true;
		btn.disabled = true;
		btn.textContent = 'Running…';
		step();
	} );
} )();
