import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    console.log(props);
    const { className } = props;

    return (
        <img
            src="/assets/images/brookstoneLogo.svg"
            alt="Brookstone School"
            className={`h-16 w-auto sm:h-20 ${className}`}
            draggable={false}
        />
    );
}
