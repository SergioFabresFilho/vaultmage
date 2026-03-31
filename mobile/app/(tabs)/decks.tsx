import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Modal,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
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

type Deck = {
  id: number;
  name: string;
  format: string | null;
  description: string | null;
  cards_count: number;
};

function formatLabel(value: string | null) {
  if (!value) return 'Casual';
  return FORMATS.find((f) => f.value === value)?.label ?? value.toUpperCase();
}

export default function DecksScreen() {
  const { token } = useAuth();
  const [decks, setDecks] = useState<Deck[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const [modalVisible, setModalVisible] = useState(false);
  const [deckName, setDeckName] = useState('');
  const [deckFormat, setDeckFormat] = useState('edh');
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
        body: JSON.stringify({ name: deckName.trim(), format: deckFormat }),
      });

      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.message || 'Failed to create deck');
      }

      setModalVisible(false);
      setDeckName('');
      setDeckFormat('edh');
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
            onPress={() => Alert.alert(item.name, `${formatLabel(item.format)} • ${item.cards_count} cards`)}
          >
            <View style={styles.deckIcon}>
              <Ionicons name="layers" size={24} color="#ffb36b" />
            </View>
            <View style={styles.deckInfo}>
              <Text style={styles.deckName}>{item.name}</Text>
              <Text style={styles.deckMeta}>
                {formatLabel(item.format)} • {item.cards_count} cards
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color="#8a7d8f" />
          </TouchableOpacity>
        )}
      />

      <Modal
        animationType="slide"
        transparent
        visible={modalVisible}
        onRequestClose={() => setModalVisible(false)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>Create Deck</Text>
              <TouchableOpacity onPress={() => setModalVisible(false)}>
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
  deckName: { color: '#fff', fontSize: 16, fontWeight: '600', marginBottom: 4 },
  deckMeta: { color: '#888', fontSize: 13 },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.8)', justifyContent: 'flex-end' },
  modalContent: {
    backgroundColor: '#1a1a2e',
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    padding: 24,
    paddingBottom: 40,
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
