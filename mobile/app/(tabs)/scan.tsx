import { API_BASE_URL } from '@/lib/api';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { manipulateAsync, SaveFormat } from 'expo-image-manipulator';
import { useFocusEffect } from 'expo-router';
import { Accelerometer } from 'expo-sensors';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Animated, ActivityIndicator, Alert, Dimensions, Image, Modal, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { useAuth } from '@/context/AuthContext';

type IdentifiedCard = {
  scryfall_id: string;
  name: string;
  set_name: string;
  type_line: string;
  rarity: string;
  image_uri: string | null;
};

export default function ScanScreen() {
  const { token } = useAuth();
  const [permission, requestPermission] = useCameraPermissions();
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [identifiedCard, setIdentifiedCard] = useState<IdentifiedCard | null>(null);
  const cameraRef = useRef<CameraView>(null);
  const flashOpacity = useRef(new Animated.Value(0)).current;
  const [isCameraActive, setIsCameraActive] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const toastOpacity = useRef(new Animated.Value(0)).current;
  const cornerReadyOpacity = useRef(new Animated.Value(0)).current;

  // Refs so callbacks can read current state without stale closures
  const scanningRef = useRef(false);
  const identifiedCardRef = useRef<IdentifiedCard | null>(null);

  // Accelerometer-based stability detection
  // We track the last N acceleration readings; if the variance stays below a threshold
  // for STABLE_DURATION_MS, we consider the phone still and fire an auto-scan.
  const ACCEL_SAMPLE_INTERVAL_MS = 100;
  const STABLE_DURATION_MS = 1000; // phone must be still for this long
  const STABLE_THRESHOLD = 0.05;   // max delta in g-force to count as still (~normal hand tremor)
  const samplesNeeded = Math.ceil(STABLE_DURATION_MS / ACCEL_SAMPLE_INTERVAL_MS);

  const lastAccelRef = useRef<{ x: number; y: number; z: number } | null>(null);
  const stableSamplesRef = useRef(0);
  const alertOpenRef = useRef(false);

  function showAlert(title: string, message: string) {
    alertOpenRef.current = true;
    stableSamplesRef.current = 0;
    Alert.alert(title, message, [{ text: 'OK', onPress: () => { alertOpenRef.current = false; } }]);
  }

  function showToast(message: string) {
    setToastMessage(message);
    Animated.sequence([
      Animated.timing(toastOpacity, { toValue: 1, duration: 200, useNativeDriver: true }),
      Animated.delay(2000),
      Animated.timing(toastOpacity, { toValue: 0, duration: 300, useNativeDriver: true }),
    ]).start(() => setToastMessage(null));
  }

  useFocusEffect(
    useCallback(() => {
      setIsCameraActive(true);
      return () => setIsCameraActive(false);
    }, [])
  );

  useEffect(() => {
    if (!isCameraActive) return;

    stableSamplesRef.current = 0;
    lastAccelRef.current = null;

    Accelerometer.setUpdateInterval(ACCEL_SAMPLE_INTERVAL_MS);

    const subscription = Accelerometer.addListener(({ x, y, z }) => {
      if (scanningRef.current || identifiedCardRef.current || alertOpenRef.current) {
        // Reset stability counter while a scan is in progress, modal is open, or alert is showing
        stableSamplesRef.current = 0;
        lastAccelRef.current = { x, y, z };
        return;
      }

      const prev = lastAccelRef.current;
      lastAccelRef.current = { x, y, z };

      if (!prev) return;

      const delta = Math.sqrt(
        Math.pow(x - prev.x, 2) +
        Math.pow(y - prev.y, 2) +
        Math.pow(z - prev.z, 2)
      );

      if (delta < STABLE_THRESHOLD) {
        stableSamplesRef.current += 1;
      } else {
        stableSamplesRef.current = 0;
      }

      if (stableSamplesRef.current >= samplesNeeded) {
        // Reset so we don't fire again immediately after this scan
        stableSamplesRef.current = 0;
        // Flash corners green to signal auto-scan
        Animated.sequence([
          Animated.timing(cornerReadyOpacity, { toValue: 1, duration: 150, useNativeDriver: true }),
          Animated.timing(cornerReadyOpacity, { toValue: 0, duration: 250, useNativeDriver: true }),
        ]).start();
        handleScan();
      }
    });

    return () => subscription.remove();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isCameraActive]);

  if (!permission) {
    return <View style={styles.container} />;
  }

  if (!permission.granted) {
    return (
      <View style={styles.container}>
        <Text style={styles.permissionText}>
          Camera access is required to scan cards.
        </Text>
        <TouchableOpacity style={styles.button} onPress={requestPermission}>
          <Text style={styles.buttonText}>Grant Permission</Text>
        </TouchableOpacity>
      </View>
    );
  }

  const { width: screenWidth, height: screenHeight } = Dimensions.get('window');
  const { width: previewWidth, height: previewHeight } = getCameraPreviewSize(screenWidth, screenHeight);

  function triggerShutter() {
    flashOpacity.setValue(1);
    Animated.timing(flashOpacity, {
      toValue: 0,
      duration: 300,
      useNativeDriver: true,
    }).start();
  }

  async function handleScan() {
    if (!cameraRef.current || scanningRef.current) return;
    triggerShutter();
    setScanning(true);
    scanningRef.current = true;
    const startedAt = Date.now();
    try {
      const captureStartedAt = Date.now();
      const photo = await cameraRef.current.takePictureAsync({ quality: 0.5 });
      const captureMs = Date.now() - captureStartedAt;
      if (!photo?.uri || !photo.width || !photo.height) throw new Error('Failed to capture image.');

      const processStartedAt = Date.now();
      const { width: screenWidth, height: screenHeight } = Dimensions.get('window');
      const { width: previewWidth, height: previewHeight } = getCameraPreviewSize(screenWidth, screenHeight);
      const normalized = await manipulateAsync(
        photo.uri,
        [],
        {
          compress: 1,
          format: SaveFormat.JPEG,
        }
      );
      const frameRect = getCardFrameCropRect(normalized.width, normalized.height, previewWidth, previewHeight);
      const regions = getCardOcrRegions(frameRect);
      const processedRegions = await Promise.all(
        regions.map((region) =>
          manipulateAsync(
            normalized.uri,
            [
              { crop: region.crop },
              { resize: { width: region.resizeWidth } },
            ],
            {
              compress: 0.35,
              format: SaveFormat.JPEG,
              base64: true,
            }
          )
        )
      );
      const processMs = Date.now() - processStartedAt;

      const images = processedRegions.map((region) => region.base64).filter((value): value is string => !!value);
      if (images.length !== processedRegions.length) throw new Error('Failed to prepare scan image.');

      const requestBody = JSON.stringify({ images });
      const requestStartedAt = Date.now();
      const response = await fetch(`${API_BASE_URL}/api/collection/scan`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: requestBody,
      });
      const responseReceivedAt = Date.now();

      const parseStartedAt = Date.now();
      const data = await response.json();
      const parseMs = Date.now() - parseStartedAt;
      const networkMs = responseReceivedAt - requestStartedAt;
      const totalMs = Date.now() - startedAt;

      console.log('[scan] timing', {
        total_ms: totalMs,
        capture_ms: captureMs,
        process_ms: processMs,
        captured_width: photo.width,
        captured_height: photo.height,
        normalized_width: normalized.width,
        normalized_height: normalized.height,
        frame_origin_x: frameRect.originX,
        frame_origin_y: frameRect.originY,
        frame_width: frameRect.width,
        frame_height: frameRect.height,
        region_count: processedRegions.length,
        regions: processedRegions.map((region, index) => ({
          index,
          origin_x: regions[index]?.crop.originX ?? 0,
          origin_y: regions[index]?.crop.originY ?? 0,
          crop_width: regions[index]?.crop.width ?? 0,
          crop_height: regions[index]?.crop.height ?? 0,
          resize_target_width: regions[index]?.resizeWidth ?? 0,
          width: region.width,
          height: region.height,
          bytes: images[index]?.length ?? 0,
        })),
        request_payload_bytes: requestBody.length,
        network_ms: networkMs,
        response_parse_ms: parseMs,
        status: response.status,
      });

      if (!response.ok) {
        const message = data?.message ?? data?.errors?.image?.[0] ?? 'Scan failed.';
        console.error('[scan] API error:', { status: response.status, message, errors: data?.errors });
        showAlert('Could not identify card', message);
        return;
      }

      const card = {
        scryfall_id: data.scryfall_id,
        name: data.name,
        set_name: data.set_name,
        type_line: data.type_line,
        rarity: data.rarity,
        image_uri: data.image_uri ?? null,
      };
      setIdentifiedCard(card);
      identifiedCardRef.current = card;
      console.log('[scan] result', {
        name: data.name,
        set_code: data.set_code,
        collector_number: data.collector_number,
      });
    } catch (e: any) {
      console.error('[scan] Unexpected error during scan:', e);
      showAlert('Error', e.message ?? 'Something went wrong.');
    } finally {
      setScanning(false);
      scanningRef.current = false;
    }
  }

  async function handleConfirm() {
    if (!identifiedCard) return;
    setSaving(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ scryfall_id: identifiedCard.scryfall_id, foil: false }),
      });
      const data = await response.json();
      if (!response.ok) {
        Alert.alert('Error', data?.message ?? 'Could not add card to collection.');
        return;
      }
      setIdentifiedCard(null);
      identifiedCardRef.current = null;
      showToast(`${identifiedCard.name} added to your collection.`);
    } catch (e: any) {
      Alert.alert('Error', e.message ?? 'Something went wrong.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <View style={styles.container}>
      <View style={[styles.cameraStage, { width: previewWidth, height: previewHeight }]}>
        {isCameraActive && <CameraView ref={cameraRef} style={StyleSheet.absoluteFill} facing="back" />}

        <View style={StyleSheet.absoluteFill} pointerEvents="none">
          <View style={styles.cameraOverlay}>
            <View style={styles.cardFrame}>
              <View style={[styles.corner, styles.topLeft]} />
              <View style={[styles.corner, styles.topRight]} />
              <View style={[styles.corner, styles.bottomLeft]} />
              <View style={[styles.corner, styles.bottomRight]} />
              {/* Green flash overlay on corners when auto-scan fires */}
              <Animated.View style={[styles.corner, styles.topLeft, styles.cornerReady, { opacity: cornerReadyOpacity }]} />
              <Animated.View style={[styles.corner, styles.topRight, styles.cornerReady, { opacity: cornerReadyOpacity }]} />
              <Animated.View style={[styles.corner, styles.bottomLeft, styles.cornerReady, { opacity: cornerReadyOpacity }]} />
              <Animated.View style={[styles.corner, styles.bottomRight, styles.cornerReady, { opacity: cornerReadyOpacity }]} />
            </View>
          </View>
        </View>
      </View>

      {/* Shutter flash overlay */}
      <Animated.View
        style={[StyleSheet.absoluteFill, styles.shutterFlash, { opacity: flashOpacity }]}
        pointerEvents="none"
      />

      <View style={styles.hint}>
        <Text style={styles.hintText}>
          {scanning ? 'Identifying card…' : 'Align card and hold still'}
        </Text>
      </View>

      <TouchableOpacity style={styles.scanButton} onPress={handleScan} disabled={scanning}>
        {scanning
          ? <ActivityIndicator color="#000" size="large" />
          : <View style={styles.scanButtonInner} />
        }
      </TouchableOpacity>

      <Modal
        visible={identifiedCard !== null}
        transparent
        animationType="fade"
        onRequestClose={() => setIdentifiedCard(null)}
      >
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            {identifiedCard?.image_uri ? (
              <Image
                source={{ uri: identifiedCard.image_uri }}
                style={styles.cardImage}
                resizeMode="contain"
              />
            ) : (
              <View style={styles.cardImagePlaceholder}>
                <Text style={styles.cardImagePlaceholderText}>No image available</Text>
              </View>
            )}
            <Text style={styles.cardName}>{identifiedCard?.name}</Text>
            {identifiedCard?.set_name ? (
              <Text style={styles.cardMeta}>{identifiedCard.set_name}</Text>
            ) : null}
            {identifiedCard?.type_line ? (
              <Text style={styles.cardMeta}>{identifiedCard.type_line}</Text>
            ) : null}
            <View style={styles.modalActions}>
              <TouchableOpacity
                style={[styles.modalButton, styles.modalButtonSecondary]}
                onPress={() => { setIdentifiedCard(null); identifiedCardRef.current = null; }}
                disabled={saving}
              >
                <Text style={styles.modalButtonSecondaryText}>Re-scan</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.modalButton, styles.modalButtonPrimary]}
                onPress={handleConfirm}
                disabled={saving}
              >
                {saving
                  ? <ActivityIndicator color="#fff" size="small" />
                  : <Text style={styles.modalButtonPrimaryText}>Add to Collection</Text>
                }
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {toastMessage !== null && (
        <Animated.View style={[styles.toast, { opacity: toastOpacity }]} pointerEvents="none">
          <Text style={styles.toastText}>{toastMessage}</Text>
        </Animated.View>
      )}
    </View>
  );
}

const CARD_WIDTH = 260;
const CARD_HEIGHT = 363; // standard MTG card ratio ~1:1.4
const CORNER_SIZE = 24;
const CORNER_THICKNESS = 3;
const CAMERA_PREVIEW_RATIO = 3 / 4;
const OCR_SIDE_INSET_RATIO = 0.02;
const OCR_TOP_INSET_RATIO = 0;
const OCR_BOTTOM_INSET_RATIO = 0;
const OCR_TOP_HEIGHT_RATIO = 0.12;
const OCR_BOTTOM_HEIGHT_RATIO = 0.12;

function getCameraPreviewSize(screenWidth: number, screenHeight: number) {
  const horizontalPadding = 24;
  const width = Math.min(screenWidth - horizontalPadding, Math.floor(screenHeight * CAMERA_PREVIEW_RATIO));
  const height = Math.floor(width / CAMERA_PREVIEW_RATIO);

  return { width, height };
}

function getCardFrameCropRect(imageWidth: number, imageHeight: number, viewportWidth: number, viewportHeight: number) {
  const frameX = (viewportWidth - CARD_WIDTH) / 2;
  const frameY = (viewportHeight - CARD_HEIGHT) / 2;

  const imageAspect = imageWidth / imageHeight;
  const viewportAspect = viewportWidth / viewportHeight;

  let scale = 1;
  let offsetX = 0;
  let offsetY = 0;

  if (imageAspect > viewportAspect) {
    scale = viewportHeight / imageHeight;
    const displayedWidth = imageWidth * scale;
    offsetX = (displayedWidth - viewportWidth) / 2;
  } else {
    scale = viewportWidth / imageWidth;
    const displayedHeight = imageHeight * scale;
    offsetY = (displayedHeight - viewportHeight) / 2;
  }

  const inset = 10;
  const cropX = Math.max(0, Math.round((frameX + inset + offsetX) / scale));
  const cropY = Math.max(0, Math.round((frameY + inset + offsetY) / scale));
  const cropWidth = Math.min(
    imageWidth - cropX,
    Math.round((CARD_WIDTH - inset * 2) / scale)
  );
  const cropHeight = Math.min(
    imageHeight - cropY,
    Math.round((CARD_HEIGHT - inset * 2) / scale)
  );

  return {
    originX: cropX,
    originY: cropY,
    width: Math.max(1, cropWidth),
    height: Math.max(1, cropHeight),
  };
}

function getCardOcrRegions(frame: { originX: number; originY: number; width: number; height: number }) {
  const sideInset = Math.round(frame.width * OCR_SIDE_INSET_RATIO);
  const topInset = Math.round(frame.height * OCR_TOP_INSET_RATIO);
  const bottomInset = Math.round(frame.height * OCR_BOTTOM_INSET_RATIO);

  const usableX = frame.originX + sideInset;
  const usableWidth = Math.max(1, frame.width - sideInset * 2);

  const topHeight = Math.max(1, Math.round(frame.height * OCR_TOP_HEIGHT_RATIO));
  const bottomHeight = Math.max(1, Math.round(frame.height * OCR_BOTTOM_HEIGHT_RATIO));

  return [
    {
      crop: {
        originX: usableX,
        originY: frame.originY + topInset,
        width: usableWidth,
        height: topHeight,
      },
      resizeWidth: 1200,
    },
    {
      crop: {
        originX: usableX,
        originY: Math.max(frame.originY, frame.originY + frame.height - bottomHeight - bottomInset),
        width: usableWidth,
        height: bottomHeight,
      },
      resizeWidth: 1200,
    },
  ];
}
const CORNER_COLOR = '#fff';

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
  },
  permissionText: {
    color: '#fff',
    textAlign: 'center',
    marginBottom: 20,
    paddingHorizontal: 32,
    fontSize: 16,
  },
  button: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 12,
    paddingHorizontal: 28,
    borderRadius: 8,
  },
  buttonText: {
    color: '#fff',
    fontWeight: '600',
    fontSize: 15,
  },

  cameraStage: {
    overflow: 'hidden',
    borderRadius: 18,
    backgroundColor: '#000',
  },
  cameraOverlay: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.18)',
  },

  // Transparent card frame
  cardFrame: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
    backgroundColor: 'transparent',
    position: 'relative',
  },

  // Corner markers
  corner: {
    position: 'absolute',
    width: CORNER_SIZE,
    height: CORNER_SIZE,
  },
  topLeft: {
    top: 0,
    left: 0,
    borderTopWidth: CORNER_THICKNESS,
    borderLeftWidth: CORNER_THICKNESS,
    borderColor: CORNER_COLOR,
    borderTopLeftRadius: 4,
  },
  topRight: {
    top: 0,
    right: 0,
    borderTopWidth: CORNER_THICKNESS,
    borderRightWidth: CORNER_THICKNESS,
    borderColor: CORNER_COLOR,
    borderTopRightRadius: 4,
  },
  bottomLeft: {
    bottom: 0,
    left: 0,
    borderBottomWidth: CORNER_THICKNESS,
    borderLeftWidth: CORNER_THICKNESS,
    borderColor: CORNER_COLOR,
    borderBottomLeftRadius: 4,
  },
  bottomRight: {
    bottom: 0,
    right: 0,
    borderBottomWidth: CORNER_THICKNESS,
    borderRightWidth: CORNER_THICKNESS,
    borderColor: CORNER_COLOR,
    borderBottomRightRadius: 4,
  },
  cornerReady: {
    borderColor: '#4ade80',
  },

  // Shutter flash
  shutterFlash: {
    backgroundColor: '#fff',
    zIndex: 10,
  },

  // Scan button
  scanButton: {
    position: 'absolute',
    bottom: 40,
    alignSelf: 'center',
    width: 72,
    height: 72,
    borderRadius: 36,
    borderWidth: 4,
    borderColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  scanButtonInner: {
    width: 54,
    height: 54,
    borderRadius: 27,
    backgroundColor: '#fff',
  },

  // Hint label
  hint: {
    position: 'absolute',
    bottom: 130,
    alignSelf: 'center',
    backgroundColor: 'rgba(0,0,0,0.5)',
    paddingVertical: 6,
    paddingHorizontal: 16,
    borderRadius: 20,
  },
  hintText: {
    color: '#fff',
    fontSize: 14,
  },

  // Card confirmation modal
  modalBackdrop: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.75)',
    alignItems: 'center',
    justifyContent: 'center',
    padding: 24,
  },
  modalCard: {
    backgroundColor: '#1a1a2e',
    borderRadius: 16,
    padding: 20,
    alignItems: 'center',
    width: '100%',
    maxWidth: 360,
  },
  cardImage: {
    width: 240,
    height: 336,
    borderRadius: 12,
    marginBottom: 16,
  },
  cardImagePlaceholder: {
    width: 240,
    height: 336,
    borderRadius: 12,
    backgroundColor: '#2a2a3e',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
  },
  cardImagePlaceholderText: {
    color: '#888',
    fontSize: 14,
  },
  cardName: {
    color: '#fff',
    fontSize: 20,
    fontWeight: '700',
    textAlign: 'center',
    marginBottom: 4,
  },
  cardMeta: {
    color: '#aaa',
    fontSize: 13,
    textAlign: 'center',
    marginBottom: 2,
  },
  modalActions: {
    flexDirection: 'row',
    gap: 12,
    marginTop: 20,
    width: '100%',
  },
  modalButton: {
    flex: 1,
    paddingVertical: 12,
    borderRadius: 8,
    alignItems: 'center',
  },
  modalButtonPrimary: {
    backgroundColor: '#6C3CE1',
  },
  modalButtonPrimaryText: {
    color: '#fff',
    fontWeight: '600',
    fontSize: 15,
  },
  modalButtonSecondary: {
    backgroundColor: 'transparent',
    borderWidth: 1,
    borderColor: '#555',
  },
  modalButtonSecondaryText: {
    color: '#ccc',
    fontWeight: '600',
    fontSize: 15,
  },

  // Toast notification
  toast: {
    position: 'absolute',
    top: 60,
    alignSelf: 'center',
    backgroundColor: 'rgba(30, 30, 50, 0.92)',
    paddingVertical: 10,
    paddingHorizontal: 20,
    borderRadius: 24,
    borderWidth: 1,
    borderColor: '#6C3CE1',
  },
  toastText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: '500',
  },
});
