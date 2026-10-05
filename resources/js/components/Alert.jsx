import { Icon } from './Icon';

const STYLES = {
    error: { box: 'bg-rose-50 border-rose-200 text-rose-800', icon: 'error' },
    info: { box: 'bg-sky-50 border-sky-200 text-sky-800', icon: 'info' },
};

export function Alert({ type, message, onClose }) {
    const style = STYLES[type];

    return (
        <div role="alert" className={`flex items-start gap-2 rounded-lg border px-4 py-3 text-sm ${style.box}`}>
            <Icon name={style.icon} className="text-[20px]" />
            <p className="flex-1 font-medium">{message}</p>
            <button type="button" onClick={onClose} className="cursor-pointer opacity-70 hover:opacity-100" aria-label="Fechar aviso">
                <Icon name="close" className="text-[18px]" />
            </button>
        </div>
    );
}