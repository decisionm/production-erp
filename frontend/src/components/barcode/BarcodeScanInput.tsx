import { CameraOutlined, ScanOutlined } from '@ant-design/icons';
import { Button, Input, type InputRef, Modal, Typography, message } from 'antd';
import { useEffect, useRef, useState } from 'react';

interface BarcodeScanInputProps {
    /** Called with the trimmed scanned/typed code, from either the hardware scanner or the camera. */
    onScan: (code: string) => void;
    placeholder?: string;
    autoFocus?: boolean;
    style?: React.CSSProperties;
}

/**
 * A handheld USB/Bluetooth barcode scanner behaves exactly like a keyboard —
 * it "types" the code into whatever's focused, then sends Enter. So the
 * primary interface here is just a text input with autofocus and an
 * onPressEnter handler; no scanner-specific code is needed for that path.
 * The camera button is a fallback for when no hardware scanner is at hand.
 */
export default function BarcodeScanInput({ onScan, placeholder, autoFocus = true, style }: BarcodeScanInputProps) {
    const [value, setValue] = useState('');
    const [cameraOpen, setCameraOpen] = useState(false);
    const inputRef = useRef<InputRef>(null);
    const videoRef = useRef<HTMLVideoElement>(null);
    const onScanRef = useRef(onScan);
    onScanRef.current = onScan;

    useEffect(() => {
        if (autoFocus) inputRef.current?.focus();
    }, [autoFocus]);

    const submit = (code: string) => {
        const trimmed = code.trim();
        if (trimmed) onScanRef.current(trimmed);
        setValue('');
    };

    useEffect(() => {
        if (!cameraOpen) return undefined;

        let cancelled = false;
        let controls: { stop: () => void } | null = null;

        // The decoder is roughly half a megabyte and the normal factory path
        // is a USB/Bluetooth scanner acting as a keyboard. Fetch the camera
        // library only when somebody explicitly opens the camera fallback.
        void (async () => {
            const { BrowserMultiFormatReader } = await import('@zxing/browser');
            if (cancelled) return;

            const nextControls = await new BrowserMultiFormatReader().decodeFromVideoDevice(
                undefined,
                videoRef.current ?? undefined,
                (result) => {
                    if (cancelled || !result) return;
                    submit(result.getText());
                    setCameraOpen(false);
                },
            );

            if (cancelled) {
                nextControls.stop();
                return;
            }

            controls = nextControls;
        })().catch(() => {
            if (!cancelled) {
                message.error('Could not access the camera — check browser permissions.');
                setCameraOpen(false);
            }
        });

        return () => {
            cancelled = true;
            controls?.stop();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cameraOpen]);

    return (
        <>
            <Input
                ref={inputRef}
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onPressEnter={() => submit(value)}
                placeholder={placeholder ?? 'Scan or type a barcode…'}
                aria-label={placeholder ?? 'Scan or type a barcode'}
                prefix={<ScanOutlined style={{ color: '#999' }} />}
                suffix={
                    <Button
                        type="text"
                        size="small"
                        icon={<CameraOutlined />}
                        aria-label="Scan with camera"
                        title="Scan with camera"
                        onClick={() => setCameraOpen(true)}
                    />
                }
                style={style}
            />
            <Modal
                title="Scan Barcode"
                open={cameraOpen}
                onCancel={() => setCameraOpen(false)}
                footer={null}
                maskClosable={false}
                destroyOnHidden
            >
                <Typography.Paragraph type="secondary">
                    Point the camera at a barcode. It'll be picked up automatically.
                </Typography.Paragraph>
                {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
                <video ref={videoRef} style={{ width: '100%', borderRadius: 4, background: '#000' }} muted />
            </Modal>
        </>
    );
}
