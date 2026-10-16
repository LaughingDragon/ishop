/**
 * @file
 * Bulk photo drop: upload many photos, group them by time taken.
 *
 * Each photo uploads on its own to /add/photo (as on "Add item"). Photos are
 * sorted by when they were taken (the camera's time, read on the server) and
 * a new item starts wherever the gap is at least N minutes (45 by default;
 * the same rule as BulkGrouping::split()). Cody can split a group at any
 * photo or merge a group with the next. The hidden field carries the groups
 * of media IDs to the server.
 */
((Drupal, drupalSettings, once) => {
  Drupal.behaviors.ishopBulk = {
    attach(context) {
      once('ishop-bulk', '.bulk-form', context).forEach((form) => {
        const settings = drupalSettings.ishopBulk || {};
        const ui = form.querySelector('.bulk-ui');
        if (!ui || !settings.uploadUrl) {
          return;
        }
        ui.hidden = false;
        const input = ui.querySelector('.bulk-input');
        const gapInput = ui.querySelector('#bulk-gap');
        const list = ui.querySelector('.bulk-groups');
        const status = ui.querySelector('.bulk-status');
        const value = form.querySelector('.bulk-groups-value');
        const create = form.querySelector('.bulk-create');
        gapInput.value = settings.gap || 45;

        // Every chosen photo: {key, file, mid, thumb, taken (ms), failed}.
        const photos = [];
        // Manual changes, by photo key: start a new item here / don't.
        const splitAt = new Set();
        const joinAt = new Set();
        let token = null;
        let uploading = 0;

        const getToken = async () => {
          if (!token) {
            const res = await fetch(settings.tokenUrl, { credentials: 'same-origin' });
            token = (await res.text()).trim();
          }
          return token;
        };

        const groups = () => {
          const gapMs = Math.max(1, parseInt(gapInput.value, 10) || 45) * 60000;
          const ready = photos.filter((p) => !p.failed).sort((a, b) => a.taken - b.taken);
          const out = [];
          let last = null;
          ready.forEach((p) => {
            const byTime = last === null || p.taken - last >= gapMs;
            const start = out.length === 0 || ((byTime || splitAt.has(p.key)) && !joinAt.has(p.key));
            if (start) {
              out.push([]);
            }
            out[out.length - 1].push(p);
            last = p.taken;
          });
          return out;
        };

        const time = (ms) => new Date(ms).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

        const render = () => {
          const gs = groups();
          list.replaceChildren();
          gs.forEach((group, gi) => {
            const li = document.createElement('li');
            li.className = 'bulk-group rm-card';
            const head = document.createElement('h3');
            head.textContent = Drupal.t('Item @n: @count photo(s), @time', {
              '@n': gi + 1,
              '@count': group.length,
              '@time': time(group[0].taken),
            });
            const strip = document.createElement('ul');
            strip.className = 'bulk-strip';
            group.forEach((p, pi) => {
              const cell = document.createElement('li');
              cell.className = 'bulk-photo';
              if (p.thumb) {
                const img = document.createElement('img');
                img.src = p.thumb;
                img.alt = '';
                cell.append(img);
              } else {
                const wait = document.createElement('span');
                wait.className = 'bulk-wait';
                wait.textContent = Drupal.t('Uploading…');
                cell.append(wait);
              }
              if (pi > 0) {
                const split = document.createElement('button');
                split.type = 'button';
                split.className = 'bulk-small';
                split.textContent = Drupal.t('Start a new item here');
                split.addEventListener('click', () => {
                  joinAt.delete(p.key);
                  splitAt.add(p.key);
                  render();
                });
                cell.append(split);
              }
              strip.append(cell);
            });
            li.append(head, strip);
            if (gi < gs.length - 1) {
              const merge = document.createElement('button');
              merge.type = 'button';
              merge.className = 'rm-button rm-button--ghost bulk-merge';
              merge.textContent = Drupal.t('Merge with the next item');
              merge.addEventListener('click', () => {
                const next = gs[gi + 1][0];
                splitAt.delete(next.key);
                joinAt.add(next.key);
                render();
              });
              li.append(merge);
            }
            list.append(li);
          });
          const failed = photos.filter((p) => p.failed).length;
          const ready = gs.every((g) => g.every((p) => p.mid));
          value.value = ready ? JSON.stringify(gs.map((g) => g.map((p) => p.mid))) : '';
          create.disabled = !ready || gs.length === 0 || uploading > 0;
          create.value = uploading > 0
            ? Drupal.t('Uploading @n photo(s)…', { '@n': uploading })
            : gs.length
              ? Drupal.formatPlural(gs.length, 'Create 1 item', 'Create @count items')
              : Drupal.t('Create the items');
          status.textContent = failed
            ? Drupal.formatPlural(failed, '1 photo could not be uploaded and was left out.', '@count photos could not be uploaded and were left out.')
            : '';
        };

        const upload = (p) =>
          getToken().then((csrf) => new Promise((resolve) => {
            const data = new FormData();
            data.append('photo', p.file, p.file.name || 'photo.jpg');
            if (p.file.lastModified) {
              data.append('taken_ms', String(p.file.lastModified));
            }
            const xhr = new XMLHttpRequest();
            xhr.open('POST', settings.uploadUrl);
            xhr.setRequestHeader('X-CSRF-Token', csrf);
            xhr.onload = () => {
              let body = {};
              try {
                body = JSON.parse(xhr.responseText);
              } catch (e) {
                body = {};
              }
              if (xhr.status === 200 && body.mid) {
                p.mid = body.mid;
                p.thumb = body.thumb;
                if (body.taken) {
                  p.taken = body.taken * 1000;
                }
              } else {
                p.failed = true;
              }
              delete p.file;
              resolve();
            };
            xhr.onerror = () => {
              p.failed = true;
              resolve();
            };
            xhr.send(data);
          }));

        input.addEventListener('change', async () => {
          const added = [...input.files].map((file, i) => ({
            key: `${Date.now()}-${i}-${file.name}`,
            file,
            mid: null,
            thumb: null,
            taken: file.lastModified || Date.now(),
            failed: false,
          }));
          input.value = '';
          photos.push(...added);
          uploading += added.length;
          render();
          for (const p of added) {
            // One at a time keeps the phone's connection and memory happy.
            // eslint-disable-next-line no-await-in-loop
            await upload(p);
            uploading -= 1;
            render();
          }
        });
        gapInput.addEventListener('input', render);
        render();
      });
    },
  };
})(Drupal, drupalSettings, once);
