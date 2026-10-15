import { Landmark } from 'lucide-react';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-emerald-800 text-white">
                <Landmark className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate font-semibold leading-tight">
                    DIGITALavera
                </span>
                <span className="text-xs text-muted-foreground">
                    ePPRISM
                </span>
            </div>
        </>
    );
}