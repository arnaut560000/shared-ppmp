import { Landmark } from 'lucide-react';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-blue-700 text-white">
                <Landmark className="size-5" />
            </div>

            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate font-semibold leading-tight text-blue-700">
                    DIGITALavera
                </span>

                <span className="text-xs font-semibold text-red-600">
                    ePPRISM
                </span>

                <div aria-hidden="true" className="mt-1 flex h-1 w-16 overflow-hidden rounded-full">
                    <span className="flex-1 bg-blue-600" />
                    <span className="flex-1 bg-red-500" />
                    <span className="flex-1 bg-yellow-400" />
                </div>
            </div>
        </>
    );
}