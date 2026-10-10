// A small stroke icon set for the admin (16 px grid, drawn here: no icon dependency).
// Icons are decorative by default; give the control itself an accessible name.
const PATHS = {
    search: 'M7 2a5 5 0 1 0 0 10A5 5 0 0 0 7 2zM11 11l3 3',
    arrowLeft: 'M10 3.5 5.5 8l4.5 4.5',
    chevronRight: 'M6 3.5 10.5 8 6 12.5',
    chevronLeft: 'M10 3.5 5.5 8l4.5 4.5',
    sidebar: 'M2 3h12v10H2zM6 3v10',
    panelRight: 'M2 3h12v10H2zM10 3v10',
    form: 'M2.5 3h11v3h-11zM2.5 8.5h11v3h-11zM4.5 4.5h3M4.5 10h3',
    bolt: 'M9 1.5 3.5 9H8l-1 5.5L12.5 7H8z',
    chevronDown: 'M3.5 6 8 10.5 12.5 6',
    chevronUp: 'M3.5 10 8 5.5l4.5 4.5',
    arrowUp: 'M8 13V3M4 7l4-4 4 4',
    arrowDown: 'M8 3v10M4 9l4 4 4-4',
    plus: 'M8 3v10M3 8h10',
    close: 'M4 4l8 8M12 4l-8 8',
    check: 'M3 8.5 6.5 12 13 4.5',
    trash: 'M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8.5h5.8l.6-8.5',
    undo: 'M5.5 3.5 2.5 6.5l3 3M2.5 6.5H10a3.5 3.5 0 0 1 0 7H7',
    redo: 'M10.5 3.5l3 3-3 3M13.5 6.5H6a3.5 3.5 0 0 0 0 7h3',
    desktop: 'M2 3h12v8H2zM6 14h4M8 11v3',
    tablet: 'M4 1.5h8v13H4zM7 12.5h2',
    mobile: 'M5 1.5h6v13H5zM7.25 12.5h1.5',
    eye: 'M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8zM8 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
    upload: 'M8 10V2.5M5 5.5l3-3 3 3M2.5 10v3h11v-3',
    globe: 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM1.5 8h13M8 1.5c1.8 1.8 2.6 4 2.6 6.5S9.8 12.7 8 14.5C6.2 12.7 5.4 10.5 5.4 8S6.2 3.3 8 1.5z',
    pages: 'M3.5 1.5h6l3 3v10h-9zM9.5 1.5v3h3M5.5 8h5M5.5 10.5h5',
    home: 'M2 7.5 8 2.5l6 5M3.5 6.5v7h9v-7M6.5 13.5v-4h3v4',
    palette:
        'M8 1.5a6.5 6.5 0 0 0 0 13c1 0 1.5-.6 1.5-1.3 0-.9-.8-1.2-.8-2 0-.7.6-1.2 1.4-1.2H12a2.5 2.5 0 0 0 2.5-2.5C14.5 4.2 11.6 1.5 8 1.5zM4.75 7.5h.01M6.75 4.75h.01M10 4.75h.01',
    layers: 'M8 1.5 14.5 5 8 8.5 1.5 5zM1.5 8 8 11.5 14.5 8M1.5 11 8 14.5 14.5 11',
    sliders: 'M2.5 4.5h7M12.5 4.5h1M2.5 11.5h1M6.5 11.5h7M11 3v3M5 10v3',
    history: 'M2.5 8a5.5 5.5 0 1 0 1.6-3.9M2.5 2.5v2.6h2.6M8 5v3.2l2 1.3',
    sparkle: 'M8 1.5l1.3 3.7 3.7 1.3-3.7 1.3L8 11.5l-1.3-3.7L3 6.5l3.7-1.3zM12.5 11l.6 1.4 1.4.6-1.4.6-.6 1.4-.6-1.4-1.4-.6 1.4-.6z',
    component: 'M8 1.5 11 4.5 8 7.5 5 4.5zM4.5 5 7.5 8 4.5 11 1.5 8zM11.5 5l3 3-3 3-3-3zM8 8.5l3 3-3 3-3-3z',
    image: 'M2 3h12v10H2zM2 10.5l3.5-3.5 3 3 2-2 3.5 3.5M10.5 6.5h.01',
    text: 'M3 3.5h10M8 3.5v9M6 12.5h4',
    move: 'M8 1.5v13M1.5 8h13M8 1.5 6 3.5M8 1.5l2 2M8 14.5l-2-2M8 14.5l2-2M1.5 8l2-2M1.5 8l2 2M14.5 8l-2-2M14.5 8l-2 2',
    grip: 'M6 3.5h.01M10 3.5h.01M6 8h.01M10 8h.01M6 12.5h.01M10 12.5h.01',
    external: 'M9 2.5h4.5V7M13.5 2.5 7.5 8.5M11.5 9.5v4h-9v-9h4',
    alert: 'M8 1.5 15 14H1zM8 6v3.5M8 11.75h.01',
    info: 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM8 7.25v4M8 4.75h.01',
    clock: 'M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM8 4.5V8l2.5 1.5',
    cloud: 'M4.5 12.5a3 3 0 0 1-.3-6 4 4 0 0 1 7.7-.9 3.25 3.25 0 0 1 .3 6.9z',
    cloudOff: 'M2 2l12 12M6.2 4.1a4 4 0 0 1 5.7 1.5 3.25 3.25 0 0 1 1.7 5.6M10.5 12.5h-6a3 3 0 0 1-.3-6',
    link: 'M6.5 9.5l3-3M7 4.5l1-1a2.8 2.8 0 0 1 4 4l-1 1M9 11.5l-1 1a2.8 2.8 0 0 1-4-4l1-1',
    reset: 'M2.5 8a5.5 5.5 0 1 0 1.6-3.9M2.5 2.5v2.6h2.6',
    dots: 'M3.5 8h.01M8 8h.01M12.5 8h.01',
    menu: 'M2.5 4h11M2.5 8h11M2.5 12h11',
    logout: 'M6 2.5H2.5v11H6M10.5 5l3 3-3 3M13.5 8H6',
    sun: 'M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM8 1.5V3M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1',
    moon: 'M13.5 9.5A5.5 5.5 0 0 1 6.5 2.5a5.5 5.5 0 1 0 7 7z',
    monitor: 'M2.5 3.5h11v7h-11zM6 13.5h4M8 10.5v3',
    save: 'M3 2.5h8l2.5 2.5v8.5h-11zM5 2.5v3.5h5V2.5M5 13.5V9.5h6v4',
    send: 'M2 8 14 2.5 10.5 14 8 9z',
    stop: 'M4 4h8v8H4z',
    target: 'M8 1.5v3M8 11.5v3M1.5 8h3M11.5 8h3M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
    lock: 'M4 7h8v6.5H4zM5.5 7V5a2.5 2.5 0 0 1 5 0v2',
    unlink: 'M6.5 9.5l3-3M3 3l10 10',
    copy: 'M5.5 5.5h8v8h-8zM10.5 5.5v-3h-8v8h3',
    columns: 'M2 2.5h12v11H2zM6 2.5v11M10 2.5v11',
    grid: 'M2.5 2.5h4.5v4.5h-4.5zM9 2.5h4.5v4.5H9zM2.5 9h4.5v4.5h-4.5zM9 9h4.5v4.5H9z',
    list: 'M5.5 4h8M5.5 8h8M5.5 12h8M2.5 4h.01M2.5 8h.01M2.5 12h.01',
    play: 'M5 3v10l8-5z',
    motion: 'M2 11.5h3M3.5 8.5h3M2 5.5h3M9.5 3.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9z',
    post: 'M2.5 2.5h6M2.5 5.5h4M2.5 13.5l.8-3 6.7-6.7 2.2 2.2-6.7 6.7zM9.5 4.3l2.2 2.2',
    tag: 'M2.5 2.5h5l6 6-5 5-6-6zM5.25 5.25h.01',
    key: 'M5.5 10.5a3 3 0 1 1 2.9-3.8l5.1 5.1v1.7h-1.7V12H10.3v-1.5H9L8.3 9.8a3 3 0 0 1-2.8.7zM4.75 7.5h.01',
} as const;

export type IconName = keyof typeof PATHS;

export function Icon({ name, className = 'size-4', title }: { name: IconName; className?: string; title?: string }) {
    return (
        <svg
            viewBox="0 0 16 16"
            className={`shrink-0 ${className}`}
            fill="none"
            stroke="currentColor"
            strokeWidth="1.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden={title ? undefined : true}
            role={title ? 'img' : undefined}
        >
            {title && <title>{title}</title>}
            <path d={PATHS[name]} />
        </svg>
    );
}
