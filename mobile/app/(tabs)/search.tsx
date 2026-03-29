import React, { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Image,
  Modal,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
  SafeAreaView,
  KeyboardAvoidingView,
  Platform,
  Alert,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '@/context/AuthContext';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_BASE_URL ?? 'http://localhost:8000';

type Card = {
  scryfall_id: string;
  name: string;
  set_code: string;
  set_name: string;
  collector_number: string;
  rarity: string;
  mana_cost: string | null;
  type_line: string;
  image_uri: string | null;
  color_identity?: string[];
};

type SearchMode = 'all' | 'collection';

function ManaCost({ cost, size = 14 }: { cost: string | null; size?: number }) {
  if (!cost) return null;

  const symbols = cost.match(/{([^}]+)}/g) || [];

  return (
    <View style={styles.manaCostContainer}>
      {symbols.map((s, i) => {
        // Scryfall symbol format: {W}, {10}, {W/U}, {W/P}
        // URL format: https://svgs.scryfall.io/card-symbols/W.svg
        // We remove { } and / for the URL
        const name = s.slice(1, -1).replace(/\//g, '').toUpperCase();
        
        // Use weserv.nl to proxy Scryfall SVGs as PNGs for React Native compatibility
        const svgUrl = `https://svgs.scryfall.io/card-symbols/${name}.svg`;
        const uri = `https://images.weserv.nl/?url=${encodeURIComponent(svgUrl)}&output=png&w=${size * 2}`;

        return (
          <Image
            key={i}
            source={{ uri }}
            style={{ width: size, height: size, marginLeft: 2 }}
            resizeMode="contain"
          />
        );
      })}
    </View>
  );
}

export default function SearchScreen() {
  const { token } = useAuth();
  const [query, setQuery] = useState('');
  const [debouncedQuery, setDebouncedQuery] = useState('');
  const [mode, setMode] = useState<SearchMode>('all');
  const [results, setResults] = useState<Card[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedCard, setSelectedCard] = useState<Card | null>(null);
  const [adding, setAdding] = useState(false);

  // Debounce query
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(query);
    }, 500);
    return () => clearTimeout(timer);
  }, [query]);

  const performSearch = useCallback(async (q: string, m: SearchMode) => {
    if (!q.trim()) {
      setResults([]);
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const endpoint = m === 'all' ? '/api/cards/search' : '/api/collection/search';
      const response = await fetch(`${API_BASE_URL}${endpoint}?q=${encodeURIComponent(q)}`, {
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });

      if (!response.ok) {
        throw new Error('Search failed');
      }

      const data = await response.json();
      setResults(data);
    } catch (err) {
      setError('Could not complete search. Please try again.');
      console.error(err);
    } finally {
      setLoading(false);
    }
  }, [token]);

  useEffect(() => {
    performSearch(debouncedQuery, mode);
  }, [debouncedQuery, mode, performSearch]);

  const addToCollection = async (card: Card) => {
    setAdding(true);
    try {
      const response = await fetch(`${API_BASE_URL}/api/collection`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({
          scryfall_id: card.scryfall_id,
          foil: false,
        }),
      });

      if (!response.ok) {
        throw new Error('Failed to add card');
      }

      Alert.alert('Success', `${card.name} added to your collection!`);
      setSelectedCard(null);
    } catch (err) {
      Alert.alert('Error', 'Could not add card to collection.');
    } finally {
      setAdding(false);
    }
  };

  return (
    <SafeAreaView style={styles.container}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
        style={{ flex: 1 }}
      >
        <View style={styles.header}>
          <View style={styles.searchBar}>
            <Ionicons name="search" size={20} color="#888" style={styles.searchIcon} />
            <TextInput
              style={styles.input}
              placeholder="Search cards..."
              placeholderTextColor="#888"
              value={query}
              onChangeText={setQuery}
              autoCorrect={false}
              clearButtonMode="while-editing"
            />
          </View>

          <View style={styles.tabBar}>
            <TouchableOpacity
              style={[styles.tab, mode === 'all' && styles.activeTab]}
              onPress={() => setMode('all')}
            >
              <Text style={[styles.tabText, mode === 'all' && styles.activeTabText]}>All Cards</Text>
            </TouchableOpacity>
            <TouchableOpacity
              style={[styles.tab, mode === 'collection' && styles.activeTab]}
              onPress={() => setMode('collection')}
            >
              <Text style={[styles.tabText, mode === 'collection' && styles.activeTabText]}>
                My Collection
              </Text>
            </TouchableOpacity>
          </View>
        </View>

        {loading ? (
          <View style={styles.centered}>
            <ActivityIndicator size="large" color="#6C3CE1" />
          </View>
        ) : error ? (
          <View style={styles.centered}>
            <Text style={styles.errorText}>{error}</Text>
          </View>
        ) : (
          <FlatList
            data={results}
            keyExtractor={(item) => item.scryfall_id}
            contentContainerStyle={styles.listContent}
            renderItem={({ item }) => (
              <TouchableOpacity style={styles.resultItem} onPress={() => setSelectedCard(item)}>
                {item.image_uri ? (
                  <Image source={{ uri: item.image_uri }} style={styles.resultImage} />
                ) : (
                  <View style={[styles.resultImage, styles.placeholderImage]}>
                    <Ionicons name="image-outline" size={24} color="#444" />
                  </View>
                )}
                <View style={styles.resultInfo}>
                  <View style={styles.nameRow}>
                    <Text style={styles.resultName} numberOfLines={1}>
                      {item.name}
                    </Text>
                    <ManaCost cost={item.mana_cost} />
                  </View>
                  <Text style={styles.resultType} numberOfLines={1}>
                    {item.type_line}
                  </Text>
                  <Text style={styles.resultMeta} numberOfLines={1}>
                    {item.set_name} ({item.set_code.toUpperCase()})
                  </Text>
                </View>
                <Ionicons name="chevron-forward" size={20} color="#444" />
              </TouchableOpacity>
            )}
            ListEmptyComponent={
              debouncedQuery ? (
                <View style={styles.centered}>
                  <Text style={styles.emptyText}>No cards found for "{debouncedQuery}"</Text>
                </View>
              ) : (
                <View style={styles.centered}>
                  <Ionicons name="search-outline" size={48} color="#2a2a3e" />
                  <Text style={styles.emptyText}>Search for any Magic card</Text>
                </View>
              )
            }
          />
        )}
      </KeyboardAvoidingView>

      <Modal
        visible={!!selectedCard}
        animationType="slide"
        transparent={true}
        onRequestClose={() => setSelectedCard(null)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            {selectedCard && (
              <>
                <TouchableOpacity
                  style={styles.closeButton}
                  onPress={() => setSelectedCard(null)}
                >
                  <Ionicons name="close" size={24} color="#fff" />
                </TouchableOpacity>

                <View style={styles.modalScroll}>
                  <Image
                    source={{ uri: selectedCard.image_uri || '' }}
                    style={styles.modalImage}
                    resizeMode="contain"
                  />
                  <View style={styles.modalInfo}>
                    <Text style={styles.modalName}>{selectedCard.name}</Text>
                    <View style={{ marginBottom: 8 }}>
                      <ManaCost cost={selectedCard.mana_cost} size={20} />
                    </View>
                    <Text style={styles.modalType}>{selectedCard.type_line}</Text>
                    <Text style={styles.modalSet}>
                      {selectedCard.set_name} • {selectedCard.rarity}
                    </Text>
                  </View>

                  <TouchableOpacity
                    style={styles.addButton}
                    onPress={() => addToCollection(selectedCard)}
                    disabled={adding}
                  >
                    {adding ? (
                      <ActivityIndicator color="#fff" />
                    ) : (
                      <>
                        <Ionicons name="add" size={20} color="#fff" />
                        <Text style={styles.addButtonText}>Add to Collection</Text>
                      </>
                    )}
                  </TouchableOpacity>
                </View>
              </>
            )}
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#0f0f1a',
  },
  header: {
    paddingTop: Platform.OS === 'android' ? 40 : 10,
    paddingHorizontal: 16,
    paddingBottom: 8,
    backgroundColor: '#0f0f1a',
    borderBottomWidth: 1,
    borderBottomColor: '#1a1a2e',
  },
  searchBar: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#1a1a2e',
    borderRadius: 10,
    paddingHorizontal: 12,
    height: 44,
    marginBottom: 12,
  },
  searchIcon: {
    marginRight: 8,
  },
  input: {
    flex: 1,
    color: '#fff',
    fontSize: 16,
  },
  tabBar: {
    flexDirection: 'row',
    backgroundColor: '#1a1a2e',
    borderRadius: 8,
    padding: 4,
  },
  tab: {
    flex: 1,
    paddingVertical: 8,
    alignItems: 'center',
    borderRadius: 6,
  },
  activeTab: {
    backgroundColor: '#6C3CE1',
  },
  tabText: {
    color: '#888',
    fontWeight: '600',
    fontSize: 14,
  },
  activeTabText: {
    color: '#fff',
  },
  listContent: {
    paddingBottom: 20,
  },
  resultItem: {
    flexDirection: 'row',
    alignItems: 'center',
    padding: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#1a1a2e',
  },
  resultImage: {
    width: 50,
    height: 70,
    borderRadius: 4,
    backgroundColor: '#1a1a2e',
  },
  placeholderImage: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  resultInfo: {
    flex: 1,
    marginLeft: 12,
  },
  nameRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 2,
  },
  resultName: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '600',
    flex: 1,
  },
  manaCostContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    marginLeft: 8,
  },
  resultType: {
    color: '#aaa',
    fontSize: 12,
    marginBottom: 2,
  },
  resultMeta: {
    color: '#666',
    fontSize: 11,
  },
  centered: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 40,
  },
  errorText: {
    color: '#ef4444',
    textAlign: 'center',
  },
  emptyText: {
    color: '#444',
    marginTop: 12,
    fontSize: 16,
    textAlign: 'center',
  },

  // Modal
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.85)',
    justifyContent: 'flex-end',
  },
  modalContent: {
    backgroundColor: '#1a1a2e',
    borderTopLeftRadius: 20,
    borderTopRightRadius: 20,
    paddingTop: 20,
    paddingBottom: 40,
    maxHeight: '90%',
  },
  closeButton: {
    position: 'absolute',
    top: 16,
    right: 16,
    zIndex: 10,
    width: 32,
    height: 32,
    borderRadius: 16,
    backgroundColor: 'rgba(255,255,255,0.1)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  modalScroll: {
    paddingHorizontal: 20,
    alignItems: 'center',
  },
  modalImage: {
    width: 250,
    height: 350,
    borderRadius: 12,
    marginBottom: 20,
  },
  modalInfo: {
    alignItems: 'center',
    marginBottom: 24,
  },
  modalName: {
    color: '#fff',
    fontSize: 22,
    fontWeight: '700',
    textAlign: 'center',
    marginBottom: 4,
  },
  modalType: {
    color: '#aaa',
    fontSize: 16,
    textAlign: 'center',
    marginBottom: 8,
  },
  modalSet: {
    color: '#666',
    fontSize: 14,
    textAlign: 'center',
  },
  addButton: {
    backgroundColor: '#6C3CE1',
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 14,
    paddingHorizontal: 32,
    borderRadius: 10,
    width: '100%',
  },
  addButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
    marginLeft: 8,
  },
});
