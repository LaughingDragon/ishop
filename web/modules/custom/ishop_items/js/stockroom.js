/**
 * @file
 * Stockroom: "Select all" and the live "N selected" count.
 *
 * Without JavaScript the checkboxes work on their own.
 */
((Drupal, once) => {
  Drupal.behaviors.ishopStockroom = {
    attach(context) {
      // Filters: leave empty fields out of the link (?size=M, not ?q=&brand=&size=M...).
      once('ishop-stock-filters', '.rm-stock-filters__form', context).forEach((filters) => {
        filters.addEventListener('submit', () => {
          filters.querySelectorAll('input, select').forEach((field) => {
            field.disabled = field.value === '';
          });
        });
      });

      once('ishop-stockroom', '.rm-stock', context).forEach((form) => {
        const boxes = [...form.querySelectorAll('.rm-stock__item input[type=checkbox]')];
        const all = form.querySelector('.rm-stock__all');
        const count = form.querySelector('.rm-stock__count');
        if (!boxes.length) {
          return;
        }
        const label = all ? all.textContent : '';
        const update = () => {
          const n = boxes.filter((b) => b.checked).length;
          if (count) {
            count.textContent = Drupal.formatPlural(n, '1 selected', '@count selected');
          }
          if (all) {
            all.textContent = n === boxes.length ? all.dataset.none : label;
          }
        };
        boxes.forEach((b) => b.addEventListener('change', update));
        if (all) {
          all.hidden = false;
          all.addEventListener('click', () => {
            const on = boxes.some((b) => !b.checked);
            boxes.forEach((b) => {
              b.checked = on;
            });
            update();
          });
        }
        update();
      });
    },
  };
})(Drupal, once);
