document.addEventListener('DOMContentLoaded', () => {
    // Initialize Lucide icons
    lucide.createIcons();

    // Sticky Header Effect
    const header = document.getElementById('header');

    window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            header.classList.add('shadow-md', 'py-2');
            header.classList.remove('py-4');
        } else {
            header.classList.remove('shadow-md', 'py-2');
            header.classList.add('py-4');
        }
    });

    // Mobile Menu Toggle (Simple implementation)
    // To be expanded if a full mobile menu is required
    const mobileMenuBtn = document.querySelector('button.md\\:hidden');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', () => {
            alert('Menu mobile sẽ được cập nhật trong phiên bản tiếp theo!');
        });
    }

    // Tab Switching Logic (Placeholder)
    const tabs = document.querySelectorAll('#pricing button');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            // Reset all tabs
            tabs.forEach(t => {
                t.classList.remove('bg-white', 'text-fpt-orange', 'shadow-sm');
                t.classList.add('text-gray-500');
            });

            // Activate clicked tab
            tab.classList.remove('text-gray-500');
            tab.classList.add('bg-white', 'text-fpt-orange', 'shadow-sm');

            // In a real app, this would filter the grid below
            // For now, we just animate the grid to simulate a change
            const grid = document.querySelector('#pricing .grid');
            grid.classList.add('opacity-50', 'scale-95');
            setTimeout(() => {
                grid.classList.remove('opacity-50', 'scale-95');
            }, 200);
        });
    });
});
