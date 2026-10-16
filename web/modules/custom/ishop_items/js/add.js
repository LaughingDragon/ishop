/**
 * @file
 * "Add item" on the phone: upload photos as they're taken, order them.
 *
 * Each photo uploads on its own (POST /add/photo with the session's CSRF
 * token), one at a time, with progress. After a camera shot the page asks
 * "Take another" / "Done" (iPhone's camera takes one photo per tap). Every
 * photo has "Make cover", up, down and remove; the hidden field holds the
 * media IDs in order, cover first.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.ishopAdd = {
    attach(context) {
      once('ishop-add', '.ia-form', context).forEach((form) => {
        const settings = drupalSettings.ishopAdd || {};
        const js = form.querySelector('.ia-js');
        if (!js || !settings.uploadUrl) {
          return;
        }
        js.hidden = false;
        form.classList.add('ia-form--js');
        const fallback = form.querySelector('.ia-fallback');
        if (fallback) {
          fallback.hidden = true;
        }

        const camera = js.querySelector('#ia-camera');
        const library = js.querySelector('#ia-library');
        const strip = js.querySelector('.ia-strip');
        const after = js.querySelector('.ia-after');
        const ids = form.querySelector('.ia-photo-ids');
        const save = form.querySelector('.ia-save');
        const queue = [];
        let busy = false;
        let token = null;

        const getToken = async () => {
          if (!token) {
            const res = await fetch(settings.tokenUrl, { credentials: 'same-origin' });
            token = (await res.text()).trim();
          }
          return token;
        };

        const sync = () => {
          const cards = [...strip.children];
          ids.value = cards
            .filter((li) => li.dataset.mid)
            .map((li) => li.dataset.mid)
            .join(',');
          cards.forEach((li, i) => {
            li.classList.toggle('ia-card--cover', i === 0);
            li.querySelector('.ia-up').disabled = i === 0;
            li.querySelector('.ia-down').disabled = i === cards.length - 1;
            li.querySelector('.ia-cover').hidden = i === 0;
          });
          const uploading = queue.length > 0 || busy;
          save.disabled = uploading;
          save.value = uploading
            ? Drupal.t('Uploading photos…')
            : Drupal.t('Save item');
        };

        const button = (cls, label, text) => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = `ia-small ${cls}`;
          b.setAttribute('aria-label', label);
          b.textContent = text;
          return b;
        };

        const card = (file) => {
          const li = document.createElement('li');
          li.className = 'ia-card ia-card--uploading';
          const thumb = document.createElement('div');
          thumb.className = 'ia-thumb';
          const progress = document.createElement('progress');
          progress.max = 100;
          progress.value = 0;
          progress.className = 'ia-progress';
          thumb.append(progress);
          const status = document.createElement('p');
          status.className = 'ia-status';
          status.textContent = Drupal.t('Uploading…');
          const badge = document.createElement('span');
          badge.className = 'ia-badge';
          badge.textContent = Drupal.t('Cover');
          const tools = document.createElement('div');
          tools.className = 'ia-tools';
          tools.append(
            button('ia-cover', Drupal.t('Make this the cover'), Drupal.t('Make cover')),
            button('ia-up', Drupal.t('Move up'), '↑'),
            button('ia-down', Drupal.t('Move down'), '↓'),
            button('ia-remove', Drupal.t('Remove this photo'), '✕'),
          );
          li.append(thumb, badge, status, tools);
          li.file = file;
          strip.append(li);
          return li;
        };

        const upload = (li) =>
          new Promise((resolve) => {
            getToken().then((csrf) => {
              const data = new FormData();
              data.append('photo', li.file, li.file.name || 'photo.jpg');
              if (li.file.lastModified) {
                data.append('taken_ms', String(li.file.lastModified));
              }
              const xhr = new XMLHttpRequest();
              xhr.open('POST', settings.uploadUrl);
              xhr.setRequestHeader('X-CSRF-Token', csrf);
              xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) {
                  li.querySelector('.ia-progress').value = Math.round((e.loaded / e.total) * 100);
                }
              };
              xhr.onload = () => {
                let body = {};
                try {
                  body = JSON.parse(xhr.responseText);
                } catch (e) {
                  body = {};
                }
                if (xhr.status === 200 && body.mid) {
                  li.dataset.mid = body.mid;
                  li.classList.remove('ia-card--uploading');
                  const img = document.createElement('img');
                  img.src = body.thumb;
                  img.alt = '';
                  li.querySelector('.ia-thumb').replaceChildren(img);
                  li.querySelector('.ia-status').textContent = '';
                  delete li.file;
                } else {
                  li.classList.add('ia-card--failed');
                  li.querySelector('.ia-status').textContent =
                    body.error || Drupal.t('Upload failed. Remove it and try again.');
                }
                resolve();
              };
              xhr.onerror = () => {
                li.classList.add('ia-card--failed');
                li.querySelector('.ia-status').textContent = Drupal.t('No connection. Remove it and try again.');
                resolve();
              };
              xhr.send(data);
            });
          });

        const pump = async () => {
          if (busy) {
            return;
          }
          busy = true;
          while (queue.length) {
            sync();
            // eslint-disable-next-line no-await-in-loop
            await upload(queue.shift());
          }
          busy = false;
          sync();
        };

        const add = (files) => {
          [...files].forEach((file) => queue.push(card(file)));
          sync();
          pump();
        };

        camera.addEventListener('change', () => {
          if (camera.files.length) {
            add(camera.files);
            after.hidden = false;
          }
          camera.value = '';
        });
        library.addEventListener('change', () => {
          if (library.files.length) {
            add(library.files);
          }
          library.value = '';
        });
        js.querySelector('.ia-another').addEventListener('click', () => camera.click());
        js.querySelector('.ia-done').addEventListener('click', () => {
          after.hidden = true;
        });

        strip.addEventListener('click', (e) => {
          const b = e.target.closest('button');
          const li = e.target.closest('.ia-card');
          if (!b || !li) {
            return;
          }
          if (b.classList.contains('ia-cover')) {
            strip.prepend(li);
          } else if (b.classList.contains('ia-up') && li.previousElementSibling) {
            li.previousElementSibling.before(li);
          } else if (b.classList.contains('ia-down') && li.nextElementSibling) {
            li.nextElementSibling.after(li);
          } else if (b.classList.contains('ia-remove')) {
            const i = queue.indexOf(li);
            if (i >= 0) {
              queue.splice(i, 1);
            }
            li.remove();
          }
          sync();
        });

        sync();
      });
    },
  };
})(Drupal, drupalSettings, once);
