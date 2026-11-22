/*
 * The genome-browser panel on an origin page.
 *
 * Nothing here runs until the reader asks for it. The panel is rendered server-side
 * as a placeholder with a button; the JBrowse bundle is 5.7 MB and most visits to an
 * origin page do not need a browser, so loading it on page load would make every
 * reader pay for a figure few of them open.
 *
 * The bundle is @jbrowse/react-linear-genome-view2, pinned, served from this site.
 * Its UMD build exports React and createRoot alongside the component, so there is no
 * second script to load and no build step here. Note that the API is the published
 * 4.3.0 one - createViewState plus JBrowseLinearGenomeView - and not the
 * LinearGenomeView component documented on jbrowse.org, which is the 5.0 beta.
 */
(function () {
  'use strict';

  var panel = document.getElementById('oridb-browser');
  if (!panel) {
    return;
  }
  var button = panel.querySelector('.jb-load');
  var slot = panel.querySelector('.jb-slot');
  var note = panel.querySelector('.jb-note');

  function say(message, isError) {
    note.textContent = message;
    note.className = 'jb-note small' + (isError ? ' jb-error' : '');
  }

  function loadBundle(src) {
    return new Promise(function (resolve, reject) {
      if (window.JBrowseReactLinearGenomeView) {
        resolve();
        return;
      }
      var el = document.createElement('script');
      el.src = src;
      el.onload = function () { resolve(); };
      el.onerror = function () { reject(new Error('the browser code could not be loaded')); };
      document.head.appendChild(el);
    });
  }

  function start() {
    button.disabled = true;
    say('Loading the browser and the data files\u2026');

    var config = null;
    fetch(panel.dataset.config, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { throw new Error('the track configuration could not be read (' + r.status + ')'); }
        return r.json();
      })
      .then(function (cfg) {
        config = cfg;
        return loadBundle(panel.dataset.bundle);
      })
      .then(function () {
        var JB = window.JBrowseReactLinearGenomeView;
        var genome = panel.dataset.assembly;
        var assembly = config.assemblies[genome];
        if (!assembly) {
          throw new Error('the hub serves no sequence for ' + genome + ' yet');
        }
        var tracks = config.tracks[genome] || [];
        var defaults = config.defaults[genome] || [];

        var state = JB.createViewState({
          assembly: assembly,
          tracks: tracks,
          location: panel.dataset.loc,
          // The origin interval itself, so it stays marked when the reader pans away.
          highlight: panel.dataset.highlight ? [panel.dataset.highlight] : [],
          defaultSession: {
            name: panel.dataset.accession || 'origin',
            view: {
              id: 'oridb-lgv',
              type: 'LinearGenomeView',
              hideHeaderOverview: true
            }
          }
        });

        defaults.forEach(function (trackId) {
          try {
            state.session.view.showTrack(trackId);
          } catch (e) {
            // A default track the hub lists but this assembly cannot show is not
            // worth failing the whole panel for.
            if (window.console) { console.warn('track not shown: ' + trackId, e); }
          }
        });

        panel.classList.add('jb-live');
        slot.hidden = false;
        JB.createRoot(slot).render(
          JB.React.createElement(JB.JBrowseLinearGenomeView, { viewState: state })
        );
        say(defaults.length + ' track(s) drawn by default; use the track selector for the rest.');
      })
      .catch(function (err) {
        button.disabled = false;
        button.textContent = 'Try again';
        say('The browser panel could not be shown: ' + err.message
            + '. The same data is in the UCSC browser, linked above.', true);
      });
  }

  button.addEventListener('click', start);
  button.hidden = false;
})();
