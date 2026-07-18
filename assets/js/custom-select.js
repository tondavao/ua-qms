// Override HTMLSelectElement value setter to sync custom selects when changed via JS
(function() {
    const descriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
    if (descriptor && descriptor.set) {
        const originalSetter = descriptor.set;
        const originalGetter = descriptor.get;
        Object.defineProperty(HTMLSelectElement.prototype, 'value', {
            get: function() {
                return originalGetter.call(this);
            },
            set: function(val) {
                originalSetter.call(this, val);
                if (typeof this.rebuildCustomOptions === 'function') {
                    this.rebuildCustomOptions();
                }
            },
        });
    }
})();

// Inject custom style rule for custom select hover options
(function() {
    const style = document.createElement('style');
    style.textContent = `
        .custom-select-container div button:hover {
            background-color: #800000 !important;
            color: #ffffff !important;
        }
        .custom-select-container div button:hover i {
            color: #ffffff !important;
        }
    `;
    document.head.appendChild(style);
})();

function initCustomSelects() {
    // Find all select elements that haven't been customized yet
    const selects = document.querySelectorAll('select:not([data-custom-select="true"])');
    
    selects.forEach(select => {
        // Skip hidden or structural selects if necessary
        if (select.classList.contains('no-custom') || select.style.display === 'none' || select.id === 'genderSelect' || select.classList.contains('no-custom-select')) {
            return;
        }

        // Mark select as customized
        select.setAttribute('data-custom-select', 'true');

        // Capture properties of original select
        const isDisabled = select.disabled;
        const selectId = select.id;
        const selectClasses = select.className;

        // Create container wrapper
        const container = document.createElement('div');
        container.className = 'relative custom-select-container';
        
        let hasWidthClass = false;
        if (selectClasses) {
            const classesList = selectClasses.split(/\s+/);
            classesList.forEach(cls => {
                if (cls.startsWith('w-') || cls.includes(':w-')) {
                    container.classList.add(cls);
                    hasWidthClass = true;
                }
            });
        }
        
        if (!hasWidthClass) {
            container.classList.add('w-full');
        }

        if (selectId) {
            container.id = `custom-container-${selectId}`;
        }

        // Hide original select visually but keep it accessible for form submissions/val fetches
        select.style.position = 'absolute';
        select.style.width = '0px';
        select.style.height = '0px';
        select.style.opacity = '0';
        select.style.pointerEvents = 'none';
        select.style.zIndex = '-1';

        // Insert container in the DOM right after the select
        select.parentNode.insertBefore(container, select.nextSibling);
        container.appendChild(select); // Move select inside container

        // Create Trigger Button
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.disabled = isDisabled;
        // Apply matching class sizing and styles
        trigger.className = `w-full flex items-center justify-between px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-left text-xs text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#800000] focus:border-transparent transition-all duration-200 ${isDisabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:border-gray-300'}`;
        
        const labelSpan = document.createElement('span');
        labelSpan.className = 'custom-select-label truncate mr-2 font-medium';
        
        const arrowIcon = document.createElement('i');
        arrowIcon.className = 'fas fa-chevron-down text-gray-400 text-[10px] transition-transform duration-200';
        
        trigger.appendChild(labelSpan);
        trigger.appendChild(arrowIcon);
        container.appendChild(trigger);

        // Create Options Menu Panel
        const menu = document.createElement('div');
        // Ensure menu opens downwards only (top-full, mt-1)
        menu.className = 'absolute z-50 left-0 right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg opacity-0 invisible -translate-y-2 origin-top pointer-events-none transition-all duration-150 ease-out max-h-60 overflow-y-auto';
        
        const optionsList = document.createElement('div');
        optionsList.className = 'py-1';
        menu.appendChild(optionsList);
        container.appendChild(menu);

        // Function to rebuild options list dynamically when original select changes
        function rebuildOptions() {
            optionsList.innerHTML = '';
            const options = select.querySelectorAll('option');
            
            // Set initial label
            let selectedOption = select.querySelector('option[selected]') || options[0];
            if (select.value) {
                const found = Array.from(options).find(opt => opt.value === select.value);
                if (found) selectedOption = found;
            }
            labelSpan.textContent = selectedOption ? selectedOption.textContent : 'Select...';

            options.forEach(opt => {
                const optButton = document.createElement('button');
                optButton.type = 'button';
                optButton.className = 'w-full text-left px-3 py-2 text-xs text-gray-700 hover:bg-[#800000] hover:text-white transition-colors focus:outline-none font-medium flex items-center justify-between';
                optButton.textContent = opt.textContent;
                
                // Active mark
                if (opt.value === select.value) {
                    optButton.classList.add('bg-red-50/50', 'text-[#800000]', 'font-semibold');
                    const checkIcon = document.createElement('i');
                    checkIcon.className = 'fas fa-check text-[10px]';
                    optButton.appendChild(checkIcon);
                }

                optButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Update value
                    select.value = opt.value;
                    labelSpan.textContent = opt.textContent;
                    
                    // Dispatch change event to trigger filters/scripts
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    closeMenu();
                });

                optionsList.appendChild(optButton);
            });
        }

        // Expose rebuild function on native select element for JS value updates
        select.rebuildCustomOptions = rebuildOptions;

        // Initialize options list
        rebuildOptions();

        // Observe option additions/deletions on the original select
        const observer = new MutationObserver(() => {
            rebuildOptions();
        });
        observer.observe(select, { childList: true, characterData: true, subtree: true });

        // Menu Toggle Operations
        let isMenuOpen = false;

        function openMenu() {
            // Close other open custom selects
            document.querySelectorAll('.custom-select-container').forEach(c => {
                if (c !== container && c.closeCustomMenu) {
                    c.closeCustomMenu();
                }
            });

            menu.classList.remove('opacity-0', 'invisible', '-translate-y-2', 'pointer-events-none');
            menu.classList.add('opacity-100', 'visible', 'translate-y-0', 'pointer-events-auto');
            arrowIcon.classList.add('rotate-180');
            isMenuOpen = true;
        }

        function closeMenu() {
            menu.classList.remove('opacity-100', 'visible', 'translate-y-0', 'pointer-events-auto');
            menu.classList.add('opacity-0', 'invisible', '-translate-y-2', 'pointer-events-none');
            arrowIcon.classList.remove('rotate-180');
            isMenuOpen = false;
        }

        // Expose close method on container so other selects can close it
        container.closeCustomMenu = closeMenu;

        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (isMenuOpen) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        // Close on click outside
        document.addEventListener('click', (e) => {
            if (!container.contains(e.target) && isMenuOpen) {
                closeMenu();
            }
        });

        // Monitor disabled changes on original select
        const disabledObserver = new MutationObserver(() => {
            trigger.disabled = select.disabled;
            if (select.disabled) {
                trigger.classList.add('opacity-50', 'cursor-not-allowed');
                trigger.classList.remove('cursor-pointer', 'hover:border-gray-300');
                closeMenu();
            } else {
                trigger.classList.remove('opacity-50', 'cursor-not-allowed');
                trigger.classList.add('cursor-pointer', 'hover:border-gray-300');
            }
        });
        disabledObserver.observe(select, { attributes: true, attributeFilter: ['disabled'] });
    });
}

// Automatically bind custom selects on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCustomSelects);
} else {
    initCustomSelects();
}
