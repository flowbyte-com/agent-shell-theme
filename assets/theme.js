/**
 * AgentShell theme.js
 * Mobile navigation toggle: converts the hidden center slot into a
 * drop-down panel on small screens. Desktop is unaffected.
 */
(function () {
    'use strict';

    var header = document.getElementById('zone-header');
    if (!header || header.dataset.agentshellNav) {
        return;
    }
    header.dataset.agentshellNav = '1';

    var centerSlot = header.querySelector('.zone-slot.slot-center');
    if (!centerSlot) {
        return;
    }

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'mobile-menu-toggle';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', 'agentshell-mobile-nav');
    button.innerHTML =
        '<span class="mobile-menu-icon" aria-hidden="true"></span>' +
        '<span class="screen-reader-text">Menu</span>';

    centerSlot.id = 'agentshell-mobile-nav';

    var rightSlot = header.querySelector('.zone-slot.slot-right');
    if (rightSlot) {
        rightSlot.insertBefore(button, rightSlot.firstChild);
    } else {
        header.appendChild(button);
    }

    function setOpen(open) {
        header.classList.toggle('mobile-nav-open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    button.addEventListener('click', function () {
        setOpen(!header.classList.contains('mobile-nav-open'));
    });

    document.addEventListener('click', function (event) {
        if (
            header.classList.contains('mobile-nav-open') &&
            !header.contains(event.target)
        ) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setOpen(false);
            button.focus();
        }
    });

    centerSlot.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            setOpen(false);
        }
    });
})();