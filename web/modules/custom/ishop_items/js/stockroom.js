/**
 * @file
 * Stockroom (redesign, punch 622): live search, Show more, select mode.
 *
 * Without JavaScript the list still works: rows open items, the chips apply
 * with Show items, and the checkboxes and bottom bar are always there.
 */
((Drupal, once) => {
  const PAGE = 50;

  Drupal.behaviors.ishopStockroom = {
    attach(context) {
      // Chips apply as soon as they change. Empty fields stay out of the
      // link (?status=sold, not ?q=&status=sold&platform=...).
      once('ishop-stock-tools', '.rm-stock-tools', context).forEach((tools) => {
        tools.addEventListener('submit', () => {
          tools.querySelectorAll('input, select').forEach((field) => {
            field.disabled = field.value === '';
          });
          document.querySelector('.rm-stock--list')?.classList.add('is-loading');
        });
        // A select is as wide as its longest option; fit each chip to the
        // choice it shows instead (CSS field-sizing isn't in Safari yet).
        const ruler = document.createElement('canvas').getContext('2d');
        tools.querySelectorAll('.rm-chipsel select').forEach((select) => {
          const style = getComputedStyle(select);
          ruler.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
          const text = select.options[select.selectedIndex]?.text || '';
          select.style.width = `${Math.ceil(ruler.measureText(text).width) + parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + 2}px`;
          select.addEventListener('change', () => tools.requestSubmit());
        });
      });

      once('ishop-stockroom', '.rm-stock--list', context).forEach((form) => {
        const rows = [...form.querySelectorAll('.rm-row')];
        const boxes = rows.map((row) => row.querySelector('.rm-row__check'));
        const bar = form.querySelector('.rm-stock__bar');
        const count = form.querySelector('.rm-stock__count');
        const all = form.querySelector('.rm-stock__all');
        const more = form.querySelector('.rm-stock__more');
        const nomatch = form.querySelector('.rm-stock__nomatch');
        const search = document.getElementById('rm-f-q');
        const select = document.querySelector('.rm-stock-head__select');
        if (!rows.length) {
          return;
        }
        const allLabel = all ? all.textContent : '';
        const selectLabel = select ? select.textContent : '';
        let shown = PAGE;
        let matches = rows;

        // Search and paging: only rows that match, PAGE at a time.
        const show = () => {
          rows.forEach((row) => {
            row.hidden = true;
          });
          matches.slice(0, shown).forEach((row) => {
            row.hidden = false;
          });
          if (more) {
            more.hidden = matches.length <= shown;
            more.textContent = Drupal.t('Show more (@count left)', { '@count': matches.length - shown });
          }
          if (nomatch) {
            nomatch.hidden = matches.length > 0;
          }
        };
        const filter = () => {
          const words = (search ? search.value : '').toLowerCase().split(/\s+/).filter(Boolean);
          matches = rows.filter((row) => words.every((w) => row.dataset.title.includes(w)));
          shown = PAGE;
          show();
        };
        more?.addEventListener('click', () => {
          shown += PAGE;
          show();
        });
        search?.addEventListener('input', filter);
        form.querySelector('.rm-stock__clear')?.addEventListener('click', () => {
          search.value = '';
          filter();
          search.focus();
        });

        // Selection: the count, Select all (rows on screen), and the bar.
        const update = () => {
          const n = boxes.filter((b) => b.checked).length;
          const visible = boxes.filter((b) => !b.closest('.rm-row').hidden);
          if (count) {
            count.textContent = Drupal.formatPlural(n, '1 selected', '@count selected');
          }
          if (bar) {
            bar.hidden = n === 0;
          }
          if (all) {
            all.textContent = visible.length && visible.every((b) => b.checked) ? all.dataset.none : allLabel;
          }
        };
        const selecting = (on) => {
          form.classList.toggle('is-selecting', on);
          if (select) {
            select.textContent = on ? select.dataset.done : selectLabel;
            select.setAttribute('aria-pressed', on ? 'true' : 'false');
          }
          if (all) {
            all.hidden = !on;
          }
          if (!on) {
            boxes.forEach((b) => {
              b.checked = false;
            });
          }
          update();
        };
        boxes.forEach((b) => b.addEventListener('change', update));
        // In select mode a tap on the row ticks it instead of opening it.
        form.querySelectorAll('.rm-row__link').forEach((link) => {
          link.addEventListener('click', (event) => {
            if (!form.classList.contains('is-selecting')) {
              return;
            }
            event.preventDefault();
            const box = link.closest('.rm-row').querySelector('.rm-row__check');
            box.checked = !box.checked;
            update();
          });
        });
        all?.addEventListener('click', () => {
          const visible = boxes.filter((b) => !b.closest('.rm-row').hidden);
          const on = visible.some((b) => !b.checked);
          visible.forEach((b) => {
            b.checked = on;
          });
          update();
        });
        if (select) {
          select.hidden = false;
          select.addEventListener('click', () => selecting(!form.classList.contains('is-selecting')));
        }
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && form.classList.contains('is-selecting')) {
            selecting(false);
          }
        });

        filter();
        // Back from Review, or a validation error: carry on selecting.
        selecting(boxes.some((b) => b.checked));
      });
    },
  };
})(Drupal, once);
