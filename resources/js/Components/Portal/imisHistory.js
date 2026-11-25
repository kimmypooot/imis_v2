/**
 * How IMIS got here, oldest first. Shown as "Our story" in the About
 * dialog, and on What's New for the versions from before IMIS kept
 * release notes and in the Road to 3.0.0. `label` is its short name there;
 * the entry marked `current` is the platform in use now.
 */
export const imisHistory = [
    {
        version: '1.0.0',
        label: 'First release',
        when: 'First half of 2025',
        title: 'One hub for the office\'s systems',
        text: 'Built with PHP, JSON and AJAX to bring the systems developed independently across CSC RO VIII into one centralized hub, giving employees a single access point to the agency\'s applications.',
    },
    {
        version: '2.0.0',
        label: 'Complete redesign',
        title: 'A complete redesign',
        text: 'As more systems joined, IMIS became part of everyday work and process owners began proposing new systems for it. Version 2 brought a more user-friendly, professional and modern interface while keeping IMIS\'s original purpose.',
    },
    {
        version: '2.7.0',
        label: 'Last release on PHP',
        when: 'September 2026',
        title: 'The last release on the original code',
        text: 'Added the official CSC branding and the What\'s New release notes. By then, tightly coupled code and accumulated technical debt made enhancements and fixes increasingly difficult.',
    },
    {
        version: '3.0.0',
        when: 'From September 2026',
        title: 'Rebuilt on the VILT stack',
        text: 'IMIS was rebuilt on Vue.js, Inertia.js, Laravel and Tailwind CSS for better code organization, maintainability and scalability, giving future enhancements and system integrations a sustainable foundation. Human Resources is now built in.',
        current: true,
    },
];

/** A development build such as 3.0.0-beta.2, not a launched release. */
export const isPreRelease = (version) => String(version ?? '').includes('-');
