/**
 * The application layer for the AgentShell Forms builder.
 *
 * The only module that knows both the models and the DOM. It owns the draft,
 * drives the transport, and updates the view.
 *
 * @package AgentShell_Forms
 */

'use strict';

function FormsApp( root, api, model ) {
    this.root = root;
    this.api = api;
    this.model = model;
    this.ui = null;
    this.previewed = false;
    this.preview_dirty = false;
}

FormsApp.prototype.init = function () {
    var self = this;

    if ( typeof AgentshellFormsUi !== 'undefined' && AgentshellFormsUi.FormsUi ) {
        this.ui = new AgentshellFormsUi.FormsUi( this.root );
    }

    this.render();

    this.root.addEventListener( 'click', function ( event ) {
        var target = event.target;

        if ( ! target || ! target.getAttribute ) {
            return;
        }

        var action = target.getAttribute( 'data-action' );
        var index = target.getAttribute( 'data-index' );

        if ( action === 'move-up' ) {
            self.move( parseInt( index, 10 ), -1 );
        } else if ( action === 'move-down' ) {
            self.move( parseInt( index, 10 ), 1 );
        } else if ( action === 'remove' ) {
            self.remove( parseInt( index, 10 ) );
        } else if ( action === 'save' ) {
            self.save();
        } else if ( action === 'publish' ) {
            self.publish();
        } else if ( action === 'discard' ) {
            self.discard();
        } else if ( action === 'preview' ) {
            self.preview();
        }
    } );
};

FormsApp.prototype.render = function () {
    if ( this.ui ) {
        this.ui.mount( this.model, this.api, null );
    }

    this.update_status();
};

FormsApp.prototype.update_status = function () {
    var chip = this.root.querySelector( '.asf-builder__status' );

    if ( ! chip ) {
        return;
    }

    var label;

    if ( this.model.dirty() && this.previewed ) {
        label = 'changes_since_preview';
    } else if ( this.model.dirty() ) {
        label = 'unsaved';
    } else if ( this.previewed ) {
        label = 'previewing';
    } else {
        label = 'saved';
    }

    chip.textContent = label;
    chip.setAttribute( 'data-status', label );
};

FormsApp.prototype.move = function ( index, direction ) {
    var result = this.model.move_field( index, direction );

    if ( ! result.moved ) {
        return;
    }

    this.model.working = result.working;
    this.previewed = false;
    this.preview_dirty = true;
    this.render();
};

FormsApp.prototype.remove = function ( index ) {
    var result = this.model.remove_field( index );

    if ( ! result.removed ) {
        return;
    }

    this.model.working = result.working;
    this.previewed = false;
    this.preview_dirty = true;
    this.render();
};

FormsApp.prototype.save = function () {
    var self = this;
    var payload = this.model.to_payload();

    this.api.save( this.model.id, payload.expected_revision, payload.definition ).then( function ( result ) {
        if ( ! result || ! result.ok ) {
            var message = result && result.body && result.body.message ? result.body.message : 'not saved';
            self.set_status( 'not_saved_validation', message );
            return;
        }

        self.model.reconcile_save( result.body.revision );
        self.render();
    } ).catch( function () {
        self.set_status( 'save_outcome_unknown', 'Save outcome could not be verified' );
    } );
};

FormsApp.prototype.publish = function () {
    var self = this;

    if ( this.model.dirty() ) {
        self.set_status( 'unsaved', 'Save before publishing' );
        return;
    }

    this.api.publish( this.model.id, this.model.revision ).then( function ( result ) {
        if ( ! result || ! result.ok ) {
            self.set_status( 'not_saved_validation', result && result.body && result.body.message ? result.body.message : 'not published' );
            return;
        }

        self.model.reconcile_publish( result.body.revision, result.body.version );
        self.render();
    } );
};

FormsApp.prototype.discard = function () {
    var self = this;

    this.api.discard( this.model.id ).then( function ( result ) {
        if ( ! result || ! result.ok ) {
            return;
        }

        self.model.discard();
        self.previewed = false;
        self.render();
    } );
};

FormsApp.prototype.preview = function () {
    var self = this;

    this.api.preview( this.model.id, this.model.working ).then( function ( result ) {
        if ( ! result || ! result.ok ) {
            return;
        }

        var panel = self.root.querySelector( '.asf-builder__preview-panel' );

        if ( panel ) {
            panel.innerHTML = result.body.html || '';
        }

        self.previewed = true;
        self.preview_dirty = false;
        self.update_status();
    } );
};

FormsApp.prototype.set_status = function ( status, message ) {
    var chip = this.root.querySelector( '.asf-builder__status' );

    if ( chip ) {
        chip.textContent = status;
        chip.setAttribute( 'data-status', status );
    }

    if ( message ) {
        var region = this.root.querySelector( '.asf-builder__status-message' );

        if ( region ) {
            region.textContent = message;
        }
    }
};

function bootstrap() {
    var mount = document.getElementById( 'agentshell-forms-builder' );

    if ( ! mount ) {
        return;
    }

    var ApiCtor = ( typeof AgentshellFormsApi !== 'undefined' && AgentshellFormsApi.FormsApi )
        ? AgentshellFormsApi.FormsApi
        : null;

    if ( ! ApiCtor ) {
        return;
    }

    var api = new ApiCtor( mount.getAttribute( 'data-rest' ), mount.getAttribute( 'data-nonce' ) );

    api.list().then( function ( result ) {
        var forms = ( result && result.body && Array.isArray( result.body.forms ) ) ? result.body.forms : [];

        if ( ! forms.length ) {
            mount.innerHTML = '<p class="asf-builder__empty">No forms yet. Create one to begin.</p>'
                + '<button type="button" class="button button-primary" data-action="create">Create a form</button>';

            mount.addEventListener( 'click', function ( event ) {
                if ( ! event.target || event.target.getAttribute( 'data-action' ) !== 'create' ) {
                    return;
                }

                var name = window.prompt( 'Name the form' );

                if ( ! name ) {
                    return;
                }

                api.create( name ).then( function ( created ) {
                    if ( created && created.ok && created.body && created.body.form_id ) {
                        window.location.reload();
                    }
                } );
            } );

            return;
        }

        api.get( forms[ 0 ].form_id ).then( function ( result ) {
            if ( ! result || ! result.ok || ! result.body ) {
                return;
            }

            var body = result.body;
            var make = ( typeof AgentshellFormsModel !== 'undefined' && AgentshellFormsModel.make_form_model )
                ? AgentshellFormsModel.make_form_model
                : null;

            if ( ! make ) {
                return;
            }

            var model = make( {
                id: body.form_id,
                name: body.name,
                slug: body.slug,
                version: body.version,
                revision: body.revision,
                working: body.working,
                published: body.published,
            } );

            var app = new FormsApp( mount, api, model );
            app.init();
        } );
    } );
}

if ( typeof document !== 'undefined' ) {
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bootstrap );
    } else {
        bootstrap();
    }
}

if ( typeof module !== 'undefined' && module.exports ) {
    module.exports = { FormsApp: FormsApp, bootstrap: bootstrap };
} else {
    ( typeof window !== 'undefined' ? window : this ).AgentshellFormsApp = { FormsApp: FormsApp, bootstrap: bootstrap };
}
EOF
