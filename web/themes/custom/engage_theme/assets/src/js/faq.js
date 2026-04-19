(function (Drupal, once) {

    Drupal.behaviors.faqToggle = {
        attach(context) {
            const items = once('faqToggle', '.collapse-arrow', context);
            if (!items.length) {
                return;
            }

            initializeItems(items);
            attachClickHandlers(items);
        }
    };

    function initializeItems(items) {
        for (const item of items) {
            const content = getContent(item);
            if (!content) {
                continue;
            }

            content.style.maxHeight = '0px';
            content.style.overflow = 'hidden';
            content.style.transition = 'max-height 0.3s ease';
        }
    }

    function attachClickHandlers(items) {
        for (const item of items) {
            const title = item.querySelector('.collapse-title');
            const content = getContent(item);

            if (!title || !content) {
                continue;
            }

            title.addEventListener('click', () => {
                toggleItem(item, content, items);
            });
        }
    }

    function toggleItem(activeItem, activeContent, items) {
        const isActive = activeItem.classList.contains('active');

        collapseAll(items);

        if (!isActive) {
            activeItem.classList.add('active');
            activeContent.style.maxHeight = activeContent.scrollHeight + 'px';
        }
    }

    function collapseAll(items) {
        for (const item of items) {
            item.classList.remove('active');
            const content = getContent(item);
            if (content) {
                content.style.maxHeight = '0px';
            }
        }
    }

    function getContent(item) {
        return item.querySelector('.collapse-content');
    }

})(Drupal, once);
