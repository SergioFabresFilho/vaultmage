import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
  Alert,
  Modal,
  TextInput,
  ScrollView,
} from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';

const FORMATS = [
  { label: 'Standard', value: 'standard' },
  { label: 'Modern', value: 'modern' },
  { label: 'Pioneer', value: 'pioneer' },
  { label: 'Legacy', value: 'legacy' },
  { label: 'Vintage', value: 'vintage' },
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

export default function DecksScreen() {
  const { token } = useAuth();
  const router = useRouter();
  const [decks, setDecks] = useState<Deck[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  
  // Modal state
  const [modalVisible, setModalVisible] = useState(false);
  const [newDeckName, setNewDeckName] = useState('');
  const [selectedFormat, setSelectedFormat] = useState('commander');
  const [isSubmitting, setIsSubmitting] = useState(false);

  const fetchDecks = useCallback(async () => {
    try {
      const response = await fetch(`${API_BASE_URL}/api/decks`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      });
      if (!response.ok) return;
      const data = await response.json();
      setDecks(data);
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

  const handleCreateDeck = async () => {
    if (!newDeckName.trim()) {
      Alert.alert('Error', 'Please enter a deck name');
      return;
    }

    setIsSubmitting(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/decks`, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json',
          Accept: 'application/json', 
          Authorization: `Bearer ${token}` 
        },
        body: JSON.stringify({ 
          name: newDeckName.trim(),
          format: selectedFormat
        }),
      });
      
      if (response.ok) {
        setModalVisible(false);
        setNewDeckName('');
        setSelectedFormat('commander');
        fetchDecks();
      } else {
        const errorData = await response.json();
        Alert.alert('Error', errorData.message || 'Failed to create deck');
      }
    } catch (error) {
      Alert.alert('Error', 'Network error. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

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
        <TouchableOpacity style={styles.addButton} onPress={() => setModalVisible(true)}>
          <Ionicons name="add-circle" size={32} color="#6C3CE1" />
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
            <Ionicons name="albums-outline" size={64} color="#2a2a3e" />
            <Text style={styles.emptyTitle}>No decks yet</Text>
            <Text style={styles.emptySubtitle}>Start building your first deck to track your strategies.</Text>
            <TouchableOpacity style={styles.createCta} onPress={() => setModalVisible(true)}>
              <Text style={styles.createCtaText}>Create New Deck</Text>
            </TouchableOpacity>
          </View>
        }
        renderItem={({ item }) => (
          <TouchableOpacity 
            style={styles.deckItem}
            onPress={() => {
              Alert.alert(item.name, `${item.format ? item.format.toUpperCase() : 'CASUAL'} • ${item.cards_count} cards`);
            }}
          >
            <View style={styles.deckIcon}>
              <Ionicons name="layers" size={24} color="#6C3CE1" />
            </View>
            <View style={styles.deckInfo}>
              <Text style={styles.deckName}>{item.name}</Text>
              <Text style={styles.deckMeta}>
                {item.format ? item.format.charAt(0).toUpperCase() + item.format.slice(1) : 'Casual'} • {item.cards_count} cards
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={20} color="#444" />
          </TouchableOpacity>
        )}
      />

      {/* Create Deck Modal */}
      <Modal
        animationType="slide"
        transparent={true}
        visible={modalVisible}
        onRequestClose={() => setModalVisible(false)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalHeader}>
              <Text style={styles.modalTitle}>New Deck</Text>
              <TouchableOpacity onPress={() => setModalVisible(false)}>
                <Ionicons name="close" size={24} color="#fff" />
              </TouchableOpacity>
            </View>

            <View style={styles.formGroup}>
              <Text style={styles.label}>Deck Name</Text>
              <TextInput
                style={styles.input}
                placeholder="Ex: My Cool Deck"
                placeholderTextColor="#666"
                value={newDeckName}
                onChangeText={setNewDeckName}
                autoFocus
              />
            </View>

            <View style={styles.formGroup}>
              <Text style={styles.label}>Format</Text>
              <View style={styles.formatGrid}>
                {FORMATS.map((format) => (
                  <TouchableOpacity
                    key={format.value}
                    style={[
                      styles.formatOption,
                      selectedFormat === format.value && styles.formatOptionSelected
                    ]}
                    onPress={() => setSelectedFormat(format.value)}
                  >
                    <Text style={[
                      styles.formatOptionText,
                      selectedFormat === format.value && styles.formatOptionTextSelected
                    ]}>
                      {format.label}
                    </Text>
                  </TouchableOpacity>
                ))}
              </View>
            </View>

            <TouchableOpacity 
              style={[styles.submitButton, isSubmitting && styles.submitButtonDisabled]} 
              onPress={handleCreateDeck}
              disabled={isSubmitting}
            >
              {isSubmitting ? (
                <ActivityIndicator color="#fff" size="small" />
              ) : (
                <Text style={styles.submitButtonText}>Create Deck</Text>
              )}
            </TouchableOpacity>
          </View>
        </View>
      </Modal>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
  },
  centered: {
    flex: 1,
    backgroundColor: '#0f0f1a',
    alignItems: 'center',
    justifyContent: 'center',
  },
  headerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingRight: 16,
    paddingTop: 56,
    paddingBottom: 12,
  },
  header: {
    color: '#fff',
    fontSize: 22,
    fontWeight: '700',
    paddingHorizontal: 16,
  },
  addButton: {
    padding: 4,
  },
  listContent: {
    paddingHorizontal: 16,
    paddingBottom: 24,
  },
  emptyContent: {
    flex: 1,
  },
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
  deckInfo: {
    flex: 1,
  },
  deckName: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
    marginBottom: 4,
  },
  deckMeta: {
    color: '#888',
    fontSize: 13,
  },
  // Empty state
  emptyState: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 40,
    marginTop: 60,
  },
  emptyTitle: {
    color: '#fff',
    fontSize: 20,
    fontWeight: '700',
    marginTop: 16,
    marginBottom: 8,
  },
  emptySubtitle: {
    color: '#888',
    fontSize: 14,
    textAlign: 'center',
    marginBottom: 24,
  },
  createCta: {
    backgroundColor: '#6C3CE1',
    paddingVertical: 12,
    paddingHorizontal: 28,
    borderRadius: 8,
  },
  createCtaText: {
    color: '#fff',
    fontWeight: '600',
    fontSize: 15,
  },
  // Modal styles
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.8)',
    justifyContent: 'flex-end',
  },
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
    marginBottom: 24,
  },
  modalTitle: {
    color: '#fff',
    fontSize: 20,
    fontWeight: '700',
  },
  formGroup: {
    marginBottom: 20,
  },
  label: {
    color: '#888',
    fontSize: 14,
    marginBottom: 8,
    fontWeight: '600',
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
  formatGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
  },
  formatOption: {
    backgroundColor: '#0f0f1a',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#2a2a3e',
  },
  formatOptionSelected: {
    backgroundColor: '#6C3CE1',
    borderColor: '#6C3CE1',
  },
  formatOptionText: {
    color: '#888',
    fontSize: 13,
  },
  formatOptionTextSelected: {
    color: '#fff',
    fontWeight: '600',
  },
  submitButton: {
    backgroundColor: '#6C3CE1',
    borderRadius: 12,
    paddingVertical: 16,
    alignItems: 'center',
    marginTop: 12,
  },
  submitButtonDisabled: {
    opacity: 0.5,
  },
  submitButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '700',
  },
});
