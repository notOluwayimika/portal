import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <img
            src="/assets/images/brookstoneLogo.svg"
            alt="Brookstone School"
            className="h-16 w-auto sm:h-20"
            draggable={false}
        />
    );
}
