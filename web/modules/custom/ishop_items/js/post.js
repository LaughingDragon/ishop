/**
 * @file
 * "Post this item": Copy buttons and "Save photos to phone".
 *
 * Without JavaScript the fields can still be selected by hand and the photos
 * downloaded one by one or as a ZIP; the buttons stay hidden.
 *
 * Saving uses the share sheet (iPhone Safari: "Save N Images" puts them in
 * Photos). Safari only opens it straight from a tap, so the photos are
 * fetched when the page loads and the tap shares them at once.
 */
((Drupal, once) => {
  function flash(button, text) {
    const label = button.dataset.label || button.textContent;
    button.dataset.label = label;
    button.textContent = text;
    setTimeout(() => {
      button.textContent = label;
    }, 1600);
  }

  async function copy(field) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(field.value);
      return;
    }
    field.select();
    if (!document.execCommand('copy')) {
      throw new Error('copy failed');
    }
  }

  Drupal.behaviors.ishopPost = {
    attach(context) {
      // On a phone the tab row scrolls sideways: bring the current tab in.
      once('ishop-post-tabs', '.ishop-post__tabs ul', context).forEach((row) => {
        const current = row.querySelector('.is-current');
        if (current && row.scrollWidth > row.clientWidth) {
          row.scrollLeft = current.offsetLeft - (row.clientWidth - current.offsetWidth) / 2;
        }
      });

      once('ishop-post-copy', '.ishop-post__copy', context).forEach((button) => {
        const field = document.getElementById(button.dataset.copy);
        button.hidden = false;
        button.addEventListener('click', () => {
          copy(field).then(
            () => flash(button, Drupal.t('Copied')),
            () => {
              field.select();
              flash(button, Drupal.t('Selected: copy it now'));
            },
          );
        });
      });

      once('ishop-post-share', '.ishop-post__share', context).forEach((button) => {
        if (!navigator.canShare) {
          return;
        }
        const probe = new File([''], 'probe.jpg', { type: 'image/jpeg' });
        if (!navigator.canShare({ files: [probe] })) {
          return;
        }
        const urls = JSON.parse(button.dataset.photos);
        const label = button.textContent;
        button.hidden = false;
        button.disabled = true;
        button.textContent = Drupal.t('Getting photos ready...');
        let files = null;
        Promise.all(
          urls.map((url, i) =>
            fetch(url, { credentials: 'same-origin' })
              .then((r) => {
                if (!r.ok) {
                  throw new Error(r.status);
                }
                return r.blob();
              })
              .then(
                (blob) =>
                  new File(
                    [blob],
                    `${button.dataset.platform}-${String(i + 1).padStart(2, '0')}.jpg`,
                    { type: 'image/jpeg' },
                  ),
              ),
          ),
        ).then(
          (ready) => {
            files = ready;
            button.disabled = false;
            button.textContent = label;
          },
          () => {
            button.textContent = Drupal.t('Photos could not load: use the ZIP');
          },
        );
        button.addEventListener('click', () => {
          if (!files) {
            return;
          }
          navigator.share({ files }).catch((e) => {
            if (e.name !== 'AbortError') {
              flash(button, Drupal.t('Could not open sharing: use the ZIP'));
            }
          });
        });
      });
    },
  };
})(Drupal, once);
