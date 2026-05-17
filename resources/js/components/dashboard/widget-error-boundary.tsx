import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    children: ReactNode;
    widgetId?: string;
}

interface State {
    hasError: boolean;
}

export class WidgetErrorBoundary extends Component<Props, State> {
    state: State = { hasError: false };

    static getDerivedStateFromError(): State {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error(`Widget error [${this.props.widgetId ?? 'unknown'}]:`, error, info);
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="bg-white border border-slate-200 rounded-lg p-4 flex items-center gap-2 text-slate-400">
                    <span className="text-xs">Widget unavailable</span>
                </div>
            );
        }
        return this.props.children;
    }
}
