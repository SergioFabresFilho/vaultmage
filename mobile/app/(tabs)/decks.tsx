import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Modal,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';

const FORMATS = [
  { label: 'Standard', value: 'standard' },
  { label: 'Pioneer', value: 'pioneer' },
  { label: 'Modern', value: 'modern' },
  { label: 'Legacy', value: 'legacy' },
  { label: 'Vintage', value: 'vintage' },
  { label: 'EDH / Commander', value: 'edh' },
  { label: 'Commander', value: 'commander' },
  { label: 'Brawl', value: 'brawl' },
  { label: 'Pauper', value: 'pauper' },
  { label: 'Casual', value: 'casual' },
];

const COLORS = [
  { symbol: 'W', label: 'White', bg: '#f9faf4', text: '#333' },
  { symbol: 'U', label: 'Blue',  bg: '#0e68ab', text: '#fff' },
  { symbol: 'B', label: 'Black', bg: '#2a2a2a', text: '#fff' },
  { symbol: 'R', label: 'Red',   bg: '#d3202a', text: '#fff' },
  { symbol: 'G', label: 'Green', bg: '#00733e', text: '#fff' },
];

type Deck = {
  id: number;
  name: string;
  format: string | null;
  description: string | null;
  color_identity: string[] | null;
  cards_sum_quantity: number;
  total_price: number | null;
  missing_price: number | null;
  is_draft: boolean;
};

function formatLabel(value: string | null) {
  if (!value) return 'Casual';
  return FORMATS.find((f) => f.value === value)?.label ?? value.toUpperCase();
}

function ColorPips({ colors }: { colors: string[] | null }) {
  if (!colors || colors.length === 0) return null;
  return (
    <View style={styles.colorPips}>
      {colors.map((c) => {
        const def = COLORS.find((x) => x.symbol === c);
        if (!def) return null;
        return (
          <View key={c} style={[styles.colorPip, { backgroundColor: def.bg }]}>
            <Text style={[styles.colorPipText, { color: def.text }]}>{c}</Text>
          </View>
        );
      })}
    </View>
  );
}

export default function DecksScreen() {
  const { token } = useAuth();
  const router = useRouter();
  const [decks, setDecks] = useState<Deck[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const [modalVisible, setModalVisible] = useState(false);
  const [deckName, setDeckName] = useState('');
  const [deckFormat, setDeckFormat] = useState('edh');
  const [deckColors, setDeckColors] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  const fetchDecks = useCallback(async () => {
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return;
      setDecks(await res.json());
    } catch {
      // network error
    }
  }, [token]);

  useEffect(() => {
    fetchDecks().finally(() => setLoading(false));
  }, [fetchDecks]);

  async function handleRefresh() {
    setRefreshing(true);
    await fetchDecks();
    setRefreshing(false);
  }

  function toggleColor(symbol: string) {
    setDeckColors((prev) =>
      prev.includes(symbol) ? prev.filter((c) => c !== symbol) : [...prev, symbol]
    );
  }

  function resetModal() {
    setDeckName('');
    setDeckFormat('edh');
    setDeckColors([]);
  }

  async function handleCreate() {
    if (!deckName.trim()) {
      Alert.alert('Error', 'Please enter a deck name.');
      return;
    }

    setSubmitting(true);
    try {
      const res = await fetch(`${API_BASE_URL}/api/decks`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          name: deckName.trim(),
          format: deckFormat,
          color_identity: deckColors.length > 0 ? deckColors : null,
        }),
      });

      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.message || 'Failed to create deck');
      }

      setModalVisible(false);
      resetModal();
      fetchDecks();
    } catch (error) {
      Alert.alert('Error', error instanceof Error ? error.message : 'Failed to create deck.');
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator color="#6C3CE1" size="large" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.headerRow}>
        <Text style={styles.header}>My Decks</Text>
        <TouchableOpacity style={styles.iconButton} onPress={() => setModalVisible(true)}>
          <Ionicons name="add" size={22} color="#fff" />
        </TouchableOpacity>
      </View>

      <FlatList
        data={decks}
        keyExtractor={(item) => item.id.toString()}
        contentContainerStyle={decks.length === 0 ? styles.emptyContent : styles.listContent}
        refreshing={refreshing}
        onRefresh={handleRefresh}
        ListEmptyComponent={
          <View style={styles.emptyState}>
            <Ionicons name="albums-outline" size={64} color="#433647" />
            <Text style={styles.emptyTitle}>No decks yet</Text>
            <Text style={styles.emptySubtitle}>
              Create an empty deck manually or ask the AI Assistant to build one for you.
            </Text>
            <TouchableOpacity style={styles.primaryCta} onPress={() => setModalVisible(true)}>
              <Text style={styles.primaryCtaText}>Create Empty Deck</Text>
            </TouchableOpacity>
          </View>
        }
        renderItem={({ item }) => (
          <TouchableOpacity
            style={styles.deckItem}
            onPress={() => router.push(`/deck/${item.id}`)}
          >
            <View style={styles.deckIcon}>
              <Ionicons name="layers" size={24} color="#ffb36b" />
            </View>
            <View style={styles.deckInfo}>
              <View style={styles.deckNameRow}>
                <Text style={styles.deckName}>{item.name}</Text>
                {item.is_draft && (
                  <View style={styles.draftBadge}>
                    <Text style={styles.draftBadgeText}>DRAFT</Text>
                  </View>
                )}
              </View>
              <View style={styles.deckMetaRow}>
                <Text style={styles.deckMeta}>
                  {formatLabel(item.format)} • {item.cards_sum_quantity} cards
                </Text>
                <ColorPips colors={item.color_identity} />
              </View>
              {item.total_price != null && (
                <View style={styles.deckPriceRow}>
                  <Text style={styles.deckPriceTotal}>${item.total_price.toFixed(2)}</Text>
                  {item.missing_price != null && item.missing_price > 0 && (
                    <Text style={styles.deckPriceMissing}> · ${item.missing_price.toFixed(2)} to buy</Text>
                  )}
                </View>
              )}
            </View>
            <Ionicons name="chevron-forward" size={20} color="#8a7d8f" />
          </TouchableOpacity>
        )}
      />

      <Modal
        animationType="slide"
        transparent
        visible={modalVisible}
        onRequestClose={() => { setModalVisible(false); resetModal(); }}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <ScrollView showsVerticalScrollIndicator={false}>
              <View style={styles.modalHeader}>
                <Text style={styles.modalTitle}>Create Deck</Text>
                <TouchableOpacity onPress={() => { setModalVisible(false); resetModal(); }}>
                  <Ionicons name="close" size={24} color="#f1e6dc" />
                </TouchableOpacity>
              </View>

              <Text style={styles.label}>Deck Name</Text>
              <TextInput
                style={styles.input}
                placeholder="Ex: Sultai Graveyard"
                placeholderTextColor="#85756a"
                value={deckName}
                onChangeText={setDeckName}
              />

              <Text style={styles.label}>Format</Text>
              <View style={styles.chipGrid}>
                {FORMATS.map((format) => (
                  <TouchableOpacity
                    key={format.value}
                    style={[styles.chip, deckFormat === format.value && styles.chipSelected]}
                    onPress={() => setDeckFormat(format.value)}
                  >
                    <Text style={[styles.chipText, deckFormat === format.value && styles.chipTextSelected]}>
                      {format.label}
                    </Text>
                  </TouchableOpacity>
                ))}
              </View>

              <Text style={styles.label}>Color Identity <Text style={styles.labelHint}>(optional — enforces card validation)</Text></Text>
              <View style={styles.colorRow}>
                {COLORS.map((color) => {
                  const selected = deckColors.includes(color.symbol);
                  return (
                    <TouchableOpacity
                      key={color.symbol}
                      style={[
                        styles.colorButton,
                        { backgroundColor: color.bg },
                        selected && styles.colorButtonSelected,
                        !selected && styles.colorButtonUnselected,
                      ]}
                      onPress={() => toggleColor(color.symbol)}
                    >
                      <Text style={[styles.colorButtonText, { color: color.text }]}>{color.symbol}</Text>
                    </TouchableOpacity>
                  );
                })}
              </View>
              {deckColors.length > 0 && (
                <Text style={styles.colorHint}>
                  Cards outside {deckColors.join('')} identity will be rejected.
                </Text>
              )}

              <TouchableOpacity
                style={[styles.primaryButton, submitting && styles.buttonDisabled]}
                onPress={handleCreate}
                disabled={submitting}
              >
                {submitting ? (
                  <ActivityIndicator color="#fff" size="small" />
                ) : (
                  <Text style={styles.primaryButtonText}>Create Deck</Text>
                )}
              </TouchableOpacity>
            </ScrollView>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#0f0f1a' },
  centered: { flex: 1, backgroundColor: '#0f0f1a', alignItems: 'center', justifyContent: 'center' },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingTop: 56,
    paddingBottom: 12,
  },
  header: { color: '#fff', fontSize: 22, fontWeight: '700' },
  iconButton: {
    width: 40,
    height: 40,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: '#1a1a2e',
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  listContent: { paddingHorizontal: 16, paddingBottom: 24 },
  emptyContent: { flexGrow: 1, paddingHorizontal: 16, paddingBottom: 24 },
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 28,
    marginTop: 48,
  },
  emptyTitle: { color: '#fff', fontSize: 20, fontWeight: '700', marginTop: 16, marginBottom: 8 },
  emptySubtitle: { color: '#888', fontSize: 14, textAlign: 'center', lineHeight: 20, marginBottom: 24 },
  primaryCta: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 12,
    paddingHorizontal: 24,
    borderRadius: 8,
    marginBottom: 10,
  },
  primaryCtaText: { color: '#fff', fontWeight: '600', fontSize: 15 },
  deckItem: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#1a1a2e',
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  deckIcon: {
    width: 48,
    height: 48,
    borderRadius: 10,
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
    marginRight: 16,
  },
  deckInfo: { flex: 1 },
  deckNameRow: { flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 4 },
  deckName: { color: '#fff', fontSize: 16, fontWeight: '600' },
  draftBadge: { backgroundColor: '#7c3c0a', borderRadius: 4, paddingHorizontal: 6, paddingVertical: 2 },
  draftBadgeText: { color: '#fbbf24', fontSize: 10, fontWeight: '700', letterSpacing: 0.5 },
  deckMetaRow: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  deckMeta: { color: '#888', fontSize: 13 },
  deckPriceRow: { flexDirection: 'row', alignItems: 'center', marginTop: 3 },
  deckPriceTotal: { color: '#7dcea0', fontSize: 12, fontWeight: '600' },
  deckPriceMissing: { color: '#e8975a', fontSize: 12 },
  colorPips: { flexDirection: 'row', gap: 3 },
  colorPip: {
    width: 16,
    height: 16,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  colorPipText: { fontSize: 9, fontWeight: '800' },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.8)', justifyContent: 'flex-end' },
  modalContent: {
    backgroundColor: '#1a1a2e',
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    padding: 24,
    paddingBottom: 40,
    maxHeight: '90%',
  },
  modalHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 18,
  },
  modalTitle: { color: '#fff', fontSize: 20, fontWeight: '700' },
  label: {
    color: '#888',
    fontSize: 13,
    fontWeight: '700',
    marginBottom: 8,
    marginTop: 12,
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  labelHint: { color: '#555', fontSize: 11, fontWeight: '400', textTransform: 'none' },
  input: {
    backgroundColor: '#0f0f1a',
    borderRadius: 12,
    padding: 16,
    color: '#fff',
    fontSize: 16,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  chipGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: {
    backgroundColor: '#0f0f1a',
    borderRadius: 999,
    paddingHorizontal: 13,
    paddingVertical: 9,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  chipSelected: { backgroundColor: '#6C3CE1', borderColor: '#6C3CE1' },
  chipText: { color: '#888', fontSize: 13, fontWeight: '600' },
  chipTextSelected: { color: '#fff' },
  colorRow: { flexDirection: 'row', gap: 10, marginTop: 4 },
  colorButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: 'transparent',
  },
  colorButtonSelected: { borderColor: '#fff', transform: [{ scale: 1.1 }] },
  colorButtonUnselected: { opacity: 0.45 },
  colorButtonText: { fontSize: 15, fontWeight: '800' },
  colorHint: { color: '#6C3CE1', fontSize: 12, marginTop: 8 },
  primaryButton: {
    backgroundColor: '#6C3CE1',
    borderRadius: 12,
    paddingVertical: 16,
    alignItems: 'center',
    marginTop: 20,
  },
  primaryButtonText: { color: '#fff', fontSize: 16, fontWeight: '700' },
  buttonDisabled: { opacity: 0.55 },
});
