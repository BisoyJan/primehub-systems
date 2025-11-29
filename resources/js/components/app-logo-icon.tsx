import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label="PrimeHub 3D logo"
        >
            {/* 3D Isometric Cube with 2x2 grid pattern matching PrimeHub login cube */}
            
            {/* Top Face - Light Blue */}
            <g transform="translate(32, 8)">
                {/* Top-left cell */}
                <polygon points="-20,10 0,0 0,10 -20,20" fill="#60a5fa" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Top-right cell */}
                <polygon points="0,0 20,10 20,20 0,10" fill="#7dd3fc" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Bottom-left cell */}
                <polygon points="-20,20 0,10 0,20 -20,30" fill="#7dd3fc" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Bottom-right cell */}
                <polygon points="0,10 20,20 20,30 0,20" fill="#60a5fa" stroke="#1e3a5f" strokeWidth="0.5"/>
            </g>
            
            {/* Left Face - Dark Blue */}
            <g transform="translate(12, 18)">
                {/* Top-left cell */}
                <polygon points="0,0 0,12 10,17 10,5" fill="#2563eb" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Top-right cell */}
                <polygon points="10,5 10,17 20,22 20,10" fill="#1d4ed8" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Bottom-left cell */}
                <polygon points="0,12 0,24 10,29 10,17" fill="#1d4ed8" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Bottom-right cell */}
                <polygon points="10,17 10,29 20,34 20,22" fill="#2563eb" stroke="#1e3a5f" strokeWidth="0.5"/>
            </g>
            
            {/* Right Face - Medium Blue */}
            <g transform="translate(32, 18)">
                {/* Top-left cell */}
                <polygon points="0,10 10,5 10,17 0,22" fill="#3b82f6" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Top-right cell */}
                <polygon points="10,5 20,0 20,12 10,17" fill="#60a5fa" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Bottom-left cell */}
                <polygon points="0,22 10,17 10,29 0,34" fill="#60a5fa" stroke="#1e3a5f" strokeWidth="0.5"/>
                {/* Bottom-right cell */}
                <polygon points="10,17 20,12 20,24 10,29" fill="#3b82f6" stroke="#1e3a5f" strokeWidth="0.5"/>
            </g>
        </svg>
    );
}
