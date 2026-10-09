import { useId } from 'react';
export function GradientControl({ value, disabled, onSet }: { value?: string; disabled: boolean; onSet(v: string | null): void }) {
    const id = useId();
    const parts = value && value !== 'none' ? value.split(' ') : ['90deg', '#102030ff', '#10203000'];
    const color = (i: number) => parts[i]!.slice(0, 7);
    const alpha = (i: number) => Math.round((parseInt(parts[i]!.slice(7) || 'ff', 16) / 255) * 100);
    const update = (i: number, text: string) => {
        const next = [...parts];
        next[i] = text;
        onSet(next.join(' '));
    };
    return (
        <div className="space-y-3 rounded-md border border-line p-3">
            <label className="flex items-center gap-2 text-xs">
                <input
                    type="checkbox"
                    disabled={disabled}
                    checked={!!value && value !== 'none'}
                    onChange={(e) => onSet(e.target.checked ? parts.join(' ') : 'none')}
                />
                Enable gradient
            </label>
            <label className="block text-xs">
                Direction
                <select className="ui-input mt-1" disabled={disabled} value={parts[0]} onChange={(e) => update(0, e.target.value)}>
                    {[
                        [0, 'Up'],
                        [45, 'Up right'],
                        [90, 'Right'],
                        [135, 'Down right'],
                        [180, 'Down'],
                        [225, 'Down left'],
                        [270, 'Left'],
                        [315, 'Up left'],
                    ].map(([angle, name]) => (
                        <option key={angle} value={angle + 'deg'}>
                            {name}
                        </option>
                    ))}
                </select>
            </label>
            {[1, 2].map((i) => (
                <div className="grid grid-cols-2 gap-2" key={i}>
                    <label className="text-xs" htmlFor={id + i + 'color'}>
                        {i === 1 ? 'Start colour' : 'End colour'}
                        <input
                            id={id + i + 'color'}
                            type="color"
                            className="mt-1 h-8 w-full"
                            disabled={disabled}
                            value={color(i)}
                            onChange={(e) => update(i, e.target.value + (parts[i]!.slice(7) || 'ff'))}
                        />
                    </label>
                    <label className="text-xs" htmlFor={id + i + 'opacity'}>
                        Opacity {alpha(i)}%
                        <input
                            id={id + i + 'opacity'}
                            type="range"
                            min="0"
                            max="100"
                            disabled={disabled}
                            value={alpha(i)}
                            onChange={(e) =>
                                update(
                                    i,
                                    color(i) +
                                        Math.round((Number(e.target.value) / 100) * 255)
                                            .toString(16)
                                            .padStart(2, '0'),
                                )
                            }
                            className="mt-3 w-full"
                        />
                    </label>
                </div>
            ))}
            <p className="text-[11px] text-muted">Overlays the background image. Use a transparent end to keep the photograph visible.</p>
        </div>
    );
}
