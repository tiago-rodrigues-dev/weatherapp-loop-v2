/** Material Symbols Outlined icon (font loaded in resources/views/app.blade.php). */
export function Icon({ name, className = '', filled = false }) {
    return (
        <span aria-hidden="true" className={`material-symbols-outlined ${filled ? 'filled' : ''} ${className}`}>
            {name}
        </span>
    );
}