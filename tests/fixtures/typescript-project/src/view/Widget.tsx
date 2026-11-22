import type { Point } from '../kinds';

export interface WidgetProps {
    label: string;
    at?: Point;
}

export const Label = (props: WidgetProps) => <span className="label">{props.label}</span>;

export default class Widget {
    render(props: WidgetProps) {
        return <div><Label {...props} /></div>;
    }
}
