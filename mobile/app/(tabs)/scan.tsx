import { CameraView, useCameraPermissions } from 'expo-camera';
import { useRef, useState } from 'react';
import { Animated, ActivityIndicator, Alert, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';

export default function ScanScreen() {
  const { token } = useAuth();
  const [permission, requestPermission] = useCameraPermissions();
  const [scanning, setScanning] = useState(false);
  const cameraRef = useRef<CameraView>(null);
  const flashOpacity = useRef(new Animated.Value(0)).current;

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

  function triggerShutter() {
    flashOpacity.setValue(1);
    Animated.timing(flashOpacity, {
      toValue: 0,
      duration: 300,
      useNativeDriver: true,
    }).start();
  }

  async function handleScan() {
    if (!cameraRef.current || scanning) return;
    triggerShutter();
    setScanning(true);
    try {
      const photo = await cameraRef.current.takePictureAsync({ base64: true, quality: 0.8 });
      if (!photo?.base64) throw new Error('Failed to capture image.');

      const response = await fetch(`${API_BASE_URL}/api/collection/scan`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ image: photo.base64 }),
      });

      const data = await response.json();

      if (!response.ok) {
        const message = data?.message ?? data?.errors?.image?.[0] ?? 'Scan failed.';
        console.error('[scan] API error:', { status: response.status, message, errors: data?.errors });
        Alert.alert('Could not identify card', message);
        return;
      }

      Alert.alert('Card found', data.name);
    } catch (e: any) {
      console.error('[scan] Unexpected error during scan:', e);
      Alert.alert('Error', e.message ?? 'Something went wrong.');
    } finally {
      setScanning(false);
    }
  }

  return (
    <View style={styles.container}>
      <CameraView ref={cameraRef} style={StyleSheet.absoluteFill} facing="back" />

      {/* Dimmed overlay with card frame cutout */}
      <View style={StyleSheet.absoluteFill} pointerEvents="none">
        {/* Top dim */}
        <View style={styles.dimTop} />

        {/* Middle row: side dims + card frame */}
        <View style={styles.middleRow}>
          <View style={styles.dimSide} />
          <View style={styles.cardFrame}>
            {/* Corner markers */}
            <View style={[styles.corner, styles.topLeft]} />
            <View style={[styles.corner, styles.topRight]} />
            <View style={[styles.corner, styles.bottomLeft]} />
            <View style={[styles.corner, styles.bottomRight]} />
          </View>
          <View style={styles.dimSide} />
        </View>

        {/* Bottom dim */}
        <View style={styles.dimBottom} />
      </View>

      {/* Shutter flash overlay */}
      <Animated.View
        style={[StyleSheet.absoluteFill, styles.shutterFlash, { opacity: flashOpacity }]}
        pointerEvents="none"
      />

      <View style={styles.hint}>
        <Text style={styles.hintText}>
          {scanning ? 'Identifying card…' : 'Align card within the frame'}
        </Text>
      </View>

      <TouchableOpacity style={styles.scanButton} onPress={handleScan} disabled={scanning}>
        {scanning
          ? <ActivityIndicator color="#000" size="large" />
          : <View style={styles.scanButtonInner} />
        }
      </TouchableOpacity>
    </View>
  );
}

const CARD_WIDTH = 260;
const CARD_HEIGHT = 363; // standard MTG card ratio ~1:1.4
const CORNER_SIZE = 24;
const CORNER_THICKNESS = 3;
const CORNER_COLOR = '#fff';

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#000',
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

  // Overlay dims
  dimTop: {
    width: '100%',
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.55)',
  },
  middleRow: {
    flexDirection: 'row',
    height: CARD_HEIGHT,
  },
  dimSide: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.55)',
  },
  dimBottom: {
    width: '100%',
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.55)',
  },

  // Transparent card frame
  cardFrame: {
    width: CARD_WIDTH,
    height: CARD_HEIGHT,
    backgroundColor: 'transparent',
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
});
