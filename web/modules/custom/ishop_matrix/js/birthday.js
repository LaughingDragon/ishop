/**
 * @file
 * Birthday mode: the party button and the song (TEMPORARY: see TEMPORARY.md).
 *
 * Browsers (iPhone Safari above all) only start audio from a tap, so nothing
 * autoplays. The dashboard's "Start the party" plays the song (it loops) and
 * becomes "Pause the party". While it plays, sessionStorage remembers it and
 * where it was, so other pages offer "Resume the party" in a floating button
 * and carry on from the same spot.
 */
((Drupal, once) => {
  const KEY = 'ishop-birthday-party';

  function load() {
    try {
      return JSON.parse(sessionStorage.getItem(KEY) || 'null');
    } catch (e) {
      return null;
    }
  }

  function store(value) {
    try {
      if (value) {
        sessionStorage.setItem(KEY, JSON.stringify(value));
      } else {
        sessionStorage.removeItem(KEY);
      }
    } catch (e) {
      // Storage blocked (private browsing): the party still works per page.
    }
  }

  Drupal.behaviors.ishopBirthday = {
    attach(context) {
      once('ishop-birthday', '#rm-party-song', context).forEach((audio) => {
        const floating = document.querySelector('.rm-party-resume');
        const toggle = document.querySelector('.rm-party-toggle');
        const button = toggle || floating;
        if (!button || !floating) {
          return;
        }
        const words = floating.dataset;
        let saved = load();
        // True from a "play" tap until a "pause" tap.
        let partying = false;

        function update() {
          if (!audio.paused) {
            button.textContent = words.pause;
          } else {
            button.textContent = saved ? words.resume : words.start;
          }
          button.setAttribute('aria-pressed', audio.paused ? 'false' : 'true');
        }

        // The dashboard has its own big button; on other pages the floating
        // one appears only if the party was playing.
        button.hidden = !toggle && !saved;
        update();

        button.addEventListener('click', () => {
          if (!audio.paused) {
            partying = false;
            audio.pause();
            return;
          }
          partying = true;
          if (saved && saved.time && audio.currentTime === 0) {
            // preload is "none": seek once the length is known. Read the time
            // now: the "play" event below replaces `saved` before then.
            const resumeAt = saved.time;
            audio.addEventListener(
              'loadedmetadata',
              () => {
                audio.currentTime = resumeAt % (audio.duration || Infinity);
              },
              { once: true },
            );
          }
          audio.play().catch(() => {
            partying = false;
            button.textContent = Drupal.t('Tap again to play');
          });
        });

        audio.addEventListener('play', () => {
          saved = { time: audio.currentTime };
          store(saved);
          // Started by the first-open launch: show the pause button here too.
          button.hidden = false;
          update();
        });
        audio.addEventListener('pause', () => {
          // Paused with the button: the party is over until the next tap.
          // Paused by leaving the page or the lock screen: keep it.
          if (!partying) {
            saved = null;
            store(null);
          }
          update();
        });
        window.addEventListener('pagehide', () => {
          if (!audio.paused) {
            store({ time: audio.currentTime });
          }
        });

        // First page of the birthday: launch the party by itself. Most
        // browsers (iPhone Safari always) refuse sound before a tap, so then
        // a full-screen sign waits for one tap anywhere.
        if (audio.hasAttribute('data-autolaunch')) {
          const gate = document.querySelector('.rm-party-gate');
          const close = () => {
            gate.hidden = true;
            document.removeEventListener('keydown', onKey);
          };
          const onKey = (e) => {
            if (e.key === 'Escape') {
              close();
            }
          };
          partying = true;
          audio.play().catch(() => {
            partying = false;
            if (!gate) {
              return;
            }
            gate.hidden = false;
            gate.querySelector('.rm-party-gate__start').focus();
            document.addEventListener('keydown', onKey);
            gate.addEventListener('click', (e) => {
              close();
              if (e.target.closest('.rm-party-gate__later')) {
                return;
              }
              partying = true;
              audio.play().catch(() => {
                partying = false;
              });
            });
          });
        }
      });
    },
  };
})(Drupal, once);
