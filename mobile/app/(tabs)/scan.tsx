import { CameraView, useCameraPermissions } from 'expo-camera';
import { StyleSheet, Text, TouchableOpacity, View } from 'react-native';

export default function ScanScreen() {
  const [permission, requestPermission] = useCameraPermissions();

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

  return (
    <View style={styles.container}>
      <CameraView style={StyleSheet.absoluteFill} facing="back" />

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

      <View style={styles.hint}>
        <Text style={styles.hintText}>Align card within the frame</Text>
      </View>
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

  // Hint label
  hint: {
    position: 'absolute',
    bottom: 60,
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
